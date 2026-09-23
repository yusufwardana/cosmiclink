<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\DiscoveredNetworkResource;
use App\Models\InternetPackage;
use App\Models\NetworkAccount;
use App\Models\NetworkDiscoveryAudit;
use App\Models\NetworkDiscoverySnapshot;
use App\Models\NetworkOperationLog;
use App\Models\ReconciliationEvidence;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\DiscoveryResult;
use App\Services\Network\NetworkDiscoveryClient;
use App\Services\Network\NetworkReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase6CNetworkDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovery_persists_a_sanitized_snapshot_and_idempotently_upserts_resources(): void
    {
        [$tenant, $user, $router] = $this->tenantRouter();
        $this->fakeDiscovery();

        $this->actingAs($user)->post(route('network.discovery.run', $router))->assertRedirect();
        $this->actingAs($user)->post(route('network.discovery.run', $router))->assertRedirect();

        $this->assertDatabaseCount('network_discovery_snapshots', 2);
        $this->assertDatabaseCount('discovered_network_resources', 4);
        $account = DiscoveredNetworkResource::where('resource_type', 'pppoe_account')->firstOrFail();
        $this->assertSame('DISCOVERED', $account->management_state);
        $this->assertStringNotContainsString('never-return', json_encode($account->normalized_data));
        $this->assertStringNotContainsString('never-return', json_encode($account->discoverySnapshot->snapshot));
        $this->assertStringNotContainsString('never-return', json_encode($account->discoverySnapshot->fresh()->toArray()));
        $this->assertStringNotContainsString('never-return', json_encode($account->fresh()->toArray()));
        $this->assertStringNotContainsString('never-return', json_encode(NetworkDiscoveryAudit::firstOrFail()->details));
        $this->assertDatabaseCount('network_operation_logs', 0);
    }

    public function test_explicit_adoption_is_tenant_scoped_local_only_and_preserves_router_configuration(): void
    {
        [$tenant, $user, $router] = $this->tenantRouter();
        $this->fakeDiscovery();
        $this->actingAs($user)->post(route('network.discovery.run', $router));
        $resource = DiscoveredNetworkResource::where('resource_type', 'pppoe_account')->firstOrFail();
        $connection = CustomerConnection::factory()->for(Customer::factory()->for($tenant))->for(InternetPackage::factory()->for($tenant))->for($router)->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->post(route('network.discovery.adopt', $resource), ['customer_connection_id' => $connection->id])->assertRedirect();

        $this->assertSame('ADOPTED', $resource->fresh()->management_state);
        $this->assertSame('existing-user-001', $connection->fresh()->networkAccount->username);
        $this->assertDatabaseCount('network_operation_logs', 0);
        $this->assertDatabaseHas('network_discovery_audits', ['action' => 'ADOPTION', 'tenant_id' => $tenant->id]);
    }

    public function test_tenant_cannot_view_or_adopt_another_tenants_discovery_resource(): void
    {
        [$tenantA, $userA] = $this->tenantRouter();
        [$tenantB, $userB, $routerB] = $this->tenantRouter();
        $this->fakeDiscovery();
        $this->actingAs($userB)->post(route('network.discovery.run', $routerB));
        $resource = DiscoveredNetworkResource::where('tenant_id', $tenantB->id)->firstOrFail();

        $this->actingAs($userA)->get(route('network.discovery.index'))->assertDontSee('existing-user-001');
        $this->actingAs($userA)->post(route('network.discovery.adopt', $resource), ['customer_connection_id' => 1])->assertForbidden();
    }

    public function test_reconciliation_classifies_new_matched_conflict_changed_and_missing_without_mutation(): void
    {
        [$tenant, $user, $router] = $this->tenantRouter();
        $this->fakeDiscovery();
        $this->actingAs($user)->post(route('network.discovery.run', $router));
        $connection = CustomerConnection::factory()->for(Customer::factory()->for($tenant))->for(InternetPackage::factory()->for($tenant))->for($router)->create(['tenant_id' => $tenant->id]);
        $resource = DiscoveredNetworkResource::where('resource_type', 'pppoe_account')->firstOrFail();
        $this->actingAs($user)->post(route('network.discovery.adopt', $resource), ['customer_connection_id' => $connection->id]);
        $resource = $resource->fresh();
        $service = app(NetworkReconciliationService::class);
        $this->assertSame('MATCHED', $service->reconcile($router)->firstWhere('resource.id', $resource->id)['status']);
        $resource->networkAccount->update(['profile' => '50M']);
        $this->assertSame('CHANGED', $service->reconcile($router)->firstWhere('resource.id', $resource->id)['status']);
        $resource->update(['network_account_id' => null]);
        $this->assertSame('CONFLICT', $service->reconcile($router)->firstWhere('resource.id', $resource->id)['status']);
        $resource->update(['customer_connection_id' => null]);
        $this->assertSame('NEW', $service->reconcile($router)->firstWhere('resource.id', $resource->id)['status']);
        $latest = NetworkDiscoverySnapshot::create(['tenant_id' => $tenant->id, 'router_id' => $router->id, 'initiated_by_user_id' => $user->id, 'provider' => 'fake', 'status' => 'success', 'discovered_at' => now(), 'summary' => [], 'snapshot' => []]);
        $resource->update(['management_state' => 'ADOPTED', 'customer_connection_id' => $connection->id, 'network_account_id' => $connection->fresh()->network_account_id, 'discovery_snapshot_id' => $resource->discovery_snapshot_id]);
        $this->assertNotSame($latest->id, $resource->fresh()->discovery_snapshot_id);
        $this->assertSame('MISSING', $service->reconcile($router)->firstWhere('resource.id', $resource->id)['status']);
        $this->assertDatabaseCount('network_operation_logs', 0);
    }

    public function test_discovery_screen_shows_safe_latest_device_metadata(): void
    {
        [$tenant, $user, $router] = $this->tenantRouter();
        $this->fakeDiscovery();

        $this->actingAs($user)->post(route('network.discovery.run', $router));

        $this->actingAs($user)->get(route('network.discovery.index'))
            ->assertOk()
            ->assertSee('CORE-01')
            ->assertSee('RouterOS: 6.49.13')
            ->assertSee('Profiles: 1')
            ->assertSee('Accounts: 1')
            ->assertSee('Pools: 1')
            ->assertSee('Queues: 1');
    }

    public function test_discovery_console_reports_persisted_counts_adaptive_columns_and_source_identity(): void
    {
        [$tenant, $user, $router] = $this->tenantRouter();
        $this->fakeDiscovery();
        $this->actingAs($user)->post(route('network.discovery.run', $router));

        // A real read-only provider snapshot outranks the earlier fake one, so the
        // console has to label the source REAL rather than simulated.
        NetworkDiscoverySnapshot::create(['tenant_id' => $tenant->id, 'router_id' => $router->id, 'initiated_by_user_id' => $user->id, 'provider' => 'routeros', 'status' => 'success', 'discovered_at' => now()->addMinute(), 'summary' => [], 'snapshot' => []]);
        $this->assertDatabaseCount('discovered_network_resources', 4);

        $this->actingAs($user)->get(route('network.discovery.index'))
            ->assertOk()
            ->assertSee('>REAL</span>', false)
            ->assertSee('ROUTEROS')
            ->assertSee($router->name)
            ->assertSee($router->host)
            ->assertSee('Total discovered')
            ->assertSee('Simple Queues')
            ->assertSee('Showing 1–4 of 4 persisted resources')
            ->assertSee('IDENTITY / TARGET');

        // Each tab renders its own columns rather than one mixed table.
        $this->actingAs($user)->get(route('network.discovery.index', ['type' => 'queue']))
            ->assertOk()->assertSee('QUEUE NAME')->assertSee('MAX LIMIT')->assertSee('queue-1');
        $this->actingAs($user)->get(route('network.discovery.index', ['type' => 'pppoe']))
            ->assertOk()->assertSee('USERNAME')->assertSee('PROFILE')->assertSee('existing-user-001');
        $this->actingAs($user)->get(route('network.discovery.index', ['type' => 'hotspot']))
            ->assertOk()->assertSee('IP / MAC');
        $this->actingAs($user)->get(route('network.discovery.index', ['type' => 'interface']))
            ->assertOk()->assertSee('INTERFACE')->assertSee('STATUS');
        $this->actingAs($user)->get(route('network.discovery.index', ['type' => 'other']))
            ->assertOk()->assertSee('pppoe_profile')->assertSee('address_pool');

        $this->assertDatabaseCount('network_operation_logs', 0);
    }

    public function test_discovery_console_filters_sorts_and_pages_without_running_another_discovery(): void
    {
        [$tenant, $user, $router] = $this->tenantRouter();
        $this->fakeDiscovery();
        $this->actingAs($user)->post(route('network.discovery.run', $router));
        $snapshot = NetworkDiscoverySnapshot::latest('id')->firstOrFail();

        // 30 persisted queues, so server-side paging has more than one page.
        foreach (range(1, 30) as $index) {
            DiscoveredNetworkResource::create([
                'tenant_id' => $tenant->id,
                'router_id' => $router->id,
                'discovery_snapshot_id' => $snapshot->id,
                'resource_type' => 'queue',
                'external_ref' => '*q'.$index,
                'name' => 'queue-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'management_state' => 'DISCOVERED',
                'fingerprint' => hash('sha256', 'queue-'.$index),
                'normalized_data' => ['external_ref' => '*q'.$index, 'name' => 'queue-'.$index, 'target' => '10.0.0.'.$index.'/32', 'max_limit' => '10M/10M'],
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        }

        $snapshots = NetworkDiscoverySnapshot::count();
        $audits = NetworkDiscoveryAudit::count();

        $this->actingAs($user)->get(route('network.discovery.index', ['type' => 'queue', 'sort' => 'name_asc', 'per_page' => 25]))
            ->assertOk()
            ->assertSee('Showing 1–25 of 31 persisted resources')
            ->assertSee('page 1 of 2')
            ->assertSee('queue-01')
            ->assertDontSee('queue-26');

        $this->actingAs($user)->get(route('network.discovery.index', ['type' => 'queue', 'sort' => 'name_asc', 'per_page' => 25, 'page' => 2]))
            ->assertOk()
            ->assertSee('Showing 26–31 of 31 persisted resources')
            ->assertSee('queue-30')
            ->assertDontSee('queue-01');

        // Search reads the persisted metadata: name, target, username and ranges.
        $this->actingAs($user)->get(route('network.discovery.index', ['q' => '10.0.0.7']))
            ->assertOk()->assertSee('queue-07')->assertDontSee('queue-08');
        $this->actingAs($user)->get(route('network.discovery.index', ['q' => 'existing-user-001']))
            ->assertOk()->assertSee('existing-user-001');

        // The ADOPTED state filter is honoured and nothing is adopted implicitly.
        $this->actingAs($user)->get(route('network.discovery.index', ['state' => 'ADOPTED']))
            ->assertOk()->assertSee('No persisted resource matches the current search, state and type filters.');

        $this->assertSame($snapshots, NetworkDiscoverySnapshot::count());
        $this->assertSame($audits, NetworkDiscoveryAudit::count());
        $this->assertSame(0, DiscoveredNetworkResource::where('management_state', 'ADOPTED')->count());
        $this->assertDatabaseCount('network_operation_logs', 0);
    }

    public function test_discovery_console_offers_explicit_adoption_for_a_discovered_pppoe_account(): void
    {
        [$tenant, $user, $router] = $this->tenantRouter();
        $this->fakeDiscovery();
        $this->actingAs($user)->post(route('network.discovery.run', $router));
        $resource = DiscoveredNetworkResource::where('resource_type', 'pppoe_account')->firstOrFail();
        $connection = CustomerConnection::factory()->for(Customer::factory()->for($tenant))->for(InternetPackage::factory()->for($tenant))->for($router)->create(['tenant_id' => $tenant->id]);

        $auditsBeforeRender = NetworkDiscoveryAudit::count();
        $resourcesBeforeRender = DiscoveredNetworkResource::count();

        $this->actingAs($user)->get(route('network.discovery.index', ['type' => 'pppoe']))
            ->assertOk()
            ->assertSee('Adopt into a customer connection')
            ->assertSee(route('network.discovery.adopt', $resource))
            ->assertSee($connection->connection_code)
            ->assertSee('Adoption writes to CosmicLink only.');

        // Rendering the offer never performs it, and no bulk action exists.
        $this->assertSame('DISCOVERED', $resource->fresh()->management_state);
        $this->assertNull($resource->fresh()->customer_connection_id);
        $this->assertSame($auditsBeforeRender, NetworkDiscoveryAudit::count());
        $this->assertSame($resourcesBeforeRender, DiscoveredNetworkResource::count());
        $this->actingAs($user)->get(route('network.discovery.index'))->assertDontSee('Adopt all');
        $this->assertDatabaseCount('network_operation_logs', 0);
    }

    public function test_discovery_console_adoption_needs_an_existing_connection_and_says_so(): void
    {
        [$tenant, $user, $router] = $this->tenantRouter();
        $this->fakeDiscovery();
        $this->actingAs($user)->post(route('network.discovery.run', $router));

        $this->actingAs($user)->get(route('network.discovery.index', ['type' => 'pppoe']))
            ->assertOk()
            ->assertSee('Adopt into a customer connection')
            ->assertSee('No customer connection exists on')
            ->assertSee('Nothing was changed on RouterOS.')
            ->assertDontSee('name="customer_connection_id"', false);
    }

    public function test_discovery_console_get_creates_no_reconciliation_evidence(): void
    {
        [$tenant, $user, $router] = $this->tenantRouter();
        $this->fakeDiscovery();
        $this->actingAs($user)->post(route('network.discovery.run', $router));

        // A read-only discovery run classifies nothing and writes no evidence.
        $this->assertDatabaseCount('network_reconciliation_evidence', 0);

        // Explicit/background reconciliation is the workflow that owns evidence.
        app(NetworkReconciliationService::class)->reconcile($router);
        $explicit = ReconciliationEvidence::count();
        $this->assertSame(4, $explicit);
        $operations = NetworkOperationLog::count();

        // Opening, searching, filtering, sorting and paging must append nothing.
        $this->actingAs($user)->get(route('network.discovery.index'))->assertOk();
        $this->actingAs($user)->get(route('network.discovery.index', ['q' => 'existing-user-001']))->assertOk();
        $this->actingAs($user)->get(route('network.discovery.index', ['type' => 'pppoe', 'state' => 'DISCOVERED', 'sort' => 'name_asc', 'per_page' => 50]))->assertOk();
        $this->actingAs($user)->get(route('network.discovery.index', ['type' => 'all', 'per_page' => 100, 'page' => 2]))->assertOk();

        $this->assertSame($explicit, ReconciliationEvidence::count());
        $this->assertSame($operations, NetworkOperationLog::count());
        $this->assertDatabaseCount('network_operation_logs', 0);
    }

    public function test_console_shows_reconciliation_state_without_evidence_and_adoption_stays_explicit(): void
    {
        [$tenant, $user, $router] = $this->tenantRouter();
        $this->fakeDiscovery();
        $this->actingAs($user)->post(route('network.discovery.run', $router));
        $resource = DiscoveredNetworkResource::where('resource_type', 'pppoe_account')->firstOrFail();
        $connection = CustomerConnection::factory()->for(Customer::factory()->for($tenant))->for(InternetPackage::factory()->for($tenant))->for($router)->create(['tenant_id' => $tenant->id]);

        // The console still renders the classification it computes in memory...
        $this->actingAs($user)->get(route('network.discovery.index', ['type' => 'pppoe']))
            ->assertOk()
            ->assertSee('[NEW]');

        // ...while persisting nothing and adopting nothing.
        $this->assertDatabaseCount('network_reconciliation_evidence', 0);
        $this->assertSame('DISCOVERED', $resource->fresh()->management_state);

        // DISCOVERED becomes ADOPTED only through the explicit operator POST.
        $this->actingAs($user)->post(route('network.discovery.adopt', $resource), ['customer_connection_id' => $connection->id])->assertRedirect();
        $this->assertSame('ADOPTED', $resource->fresh()->management_state);
        $this->assertNotSame('MANAGED', $resource->fresh()->management_state);
        $this->assertDatabaseHas('network_discovery_audits', ['action' => 'ADOPTION', 'discovered_network_resource_id' => $resource->id]);

        // The adopted picture is classified as MATCHED, still without evidence.
        $this->actingAs($user)->get(route('network.discovery.index', ['state' => 'ADOPTED']))
            ->assertOk()
            ->assertSee('MATCHED');

        $this->assertDatabaseCount('network_reconciliation_evidence', 0);
        $this->assertDatabaseCount('network_operation_logs', 0);
    }

    public function test_reconciliation_api_get_creates_no_evidence_and_keeps_its_response(): void
    {
        [$tenant, $user, $router] = $this->tenantRouter();
        $snapshot = NetworkDiscoverySnapshot::create(['tenant_id' => $tenant->id, 'router_id' => $router->id, 'initiated_by_user_id' => $user->id, 'provider' => 'routeros', 'status' => 'success', 'discovered_at' => now(), 'summary' => [], 'snapshot' => []]);
        DiscoveredNetworkResource::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'discovery_snapshot_id' => $snapshot->id,
            'resource_type' => 'pppoe_account',
            'external_ref' => '*1',
            'name' => 'api-subscriber',
            'management_state' => 'DISCOVERED',
            'fingerprint' => hash('sha256', 'api-subscriber'),
            'normalized_data' => ['external_ref' => '*1', 'username' => 'api-subscriber', 'profile' => '20M', 'enabled' => true],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        // Explicit/background reconciliation stays the workflow that writes evidence.
        app(NetworkReconciliationService::class)->reconcile($router);
        $explicit = ReconciliationEvidence::count();
        $this->assertSame(1, $explicit);

        // The read answers with exactly the same resource, status and suggestion shape.
        $payload = $this->actingAs($user)->getJson(route('api.v1.reconciliation.index'))->assertOk()->json();
        $this->assertCount(1, $payload['data']);
        $this->assertSame(['resource', 'status', 'suggestion'], array_keys($payload['data'][0]));
        $this->assertSame(['id', 'router_id', 'name', 'resource_type', 'management_state', 'last_seen_at', 'normalized_data'], array_keys($payload['data'][0]['resource']));
        $this->assertSame('api-subscriber', $payload['data'][0]['resource']['name']);
        $this->assertSame('NEW', $payload['data'][0]['status']);
        $this->assertNull($payload['data'][0]['suggestion']);

        // A matching local account still produces the same suggestion payload.
        $account = NetworkAccount::create(['tenant_id' => $tenant->id, 'router_id' => $router->id, 'username' => 'api-subscriber', 'profile' => '20M', 'status' => 'active', 'encrypted_secret' => null, 'metadata' => []]);
        $connection = CustomerConnection::factory()->for(Customer::factory()->for($tenant))->for(InternetPackage::factory()->for($tenant))->for($router)->create(['tenant_id' => $tenant->id, 'network_account_id' => $account->id]);

        $this->actingAs($user)->getJson(route('api.v1.reconciliation.index'))
            ->assertOk()
            ->assertJsonPath('data.0.status', 'NEW')
            ->assertJsonPath('data.0.suggestion.customer_connection_id', $connection->id)
            ->assertJsonPath('data.0.suggestion.username', 'api-subscriber')
            ->assertJsonPath('data.0.suggestion.reason', 'Exact PPPoE username + same router');

        // Reading the endpoint appends no evidence and mutates nothing.
        $this->assertSame($explicit, ReconciliationEvidence::count());
        $this->assertDatabaseCount('network_operation_logs', 0);
    }

    private function tenantRouter(): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create();
        $router = Router::factory()->for($tenant)->create();

        return [$tenant, $user, $router];
    }

    private function fakeDiscovery(): void
    {
        app()->bind(NetworkDiscoveryClient::class, fn () => new class implements NetworkDiscoveryClient
        {
            public function discover(Router $router): DiscoveryResult
            {
                return new DiscoveryResult(true, 'Read-only discovery complete', null, ['provider' => 'fake', 'router_ref' => (string) $router->id, 'discovered_at' => now()->toIso8601String(), 'snapshot' => ['device' => ['name' => 'CORE-01', 'routeros_version' => '6.49.13'], 'profiles' => [['external_ref' => '10m', 'name' => '10M']], 'accounts' => [['external_ref' => 'existing-user-001', 'username' => 'existing-user-001', 'profile' => '10M', 'enabled' => true, 'password' => 'never-return', 'pass' => 'never-return', 'authorization' => 'never-return', 'credential' => 'never-return']], 'address_pools' => [['external_ref' => 'pppoe-pool', 'name' => 'pppoe-pool']], 'queues' => [['external_ref' => 'queue-1', 'name' => 'queue-1']]]]);
            }
        });
    }
}
