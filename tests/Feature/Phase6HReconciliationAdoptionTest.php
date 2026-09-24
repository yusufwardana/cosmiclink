<?php

namespace Tests\Feature;

use App\Actions\ProcessOverdueBilling;
use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\DiscoveredNetworkResource;
use App\Models\InternetPackage;
use App\Models\Invoice;
use App\Models\NetworkAccount;
use App\Models\NetworkOperationLog;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\DiscoveryResult;
use App\Services\Network\NetworkDiscoveryClient;
use App\Services\Network\NetworkReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase6HReconciliationAdoptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_suggestion_is_explainable_and_does_not_mutate_relationships(): void
    {
        [$tenant, $user, $router, $resource, $connection] = $this->discoveredFixture();
        NetworkAccount::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'username' => 'pppoe-yusuf',
            'profile' => 'HOME-20M',
            'status' => 'active',
        ])->connections()->save($connection);

        $result = app(NetworkReconciliationService::class)->reconcile($router)->firstWhere('resource.id', $resource->id);

        $this->assertSame('NEW', $result['status']);
        $this->assertSame('pppoe-yusuf', $result['suggestion']['username']);
        $this->assertSame('Exact PPPoE username + same router', $result['suggestion']['reason']);
        $this->assertNull($resource->fresh()->customer_connection_id);
        $this->assertNull($resource->fresh()->network_account_id);
        $this->assertNotNull($connection->fresh()->network_account_id);
        $this->assertDatabaseCount('network_operation_logs', 0);
    }

    public function test_explicit_adoption_reuses_existing_account_without_a_secret_or_network_operation(): void
    {
        [$tenant, $user, $router, $resource, $connection] = $this->discoveredFixture();
        $account = NetworkAccount::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'username' => 'pppoe-yusuf',
            'profile' => 'HOME-20M',
            'status' => 'active',
            'encrypted_secret' => null,
        ]);

        $this->actingAs($user)->post(route('network.discovery.adopt', $resource), [
            'customer_connection_id' => $connection->id,
        ])->assertRedirect();

        $this->assertSame('ADOPTED', $resource->fresh()->management_state);
        $this->assertSame($account->id, $connection->fresh()->network_account_id);
        $this->assertNull($account->fresh()->encrypted_secret);
        $this->assertSame(1, NetworkAccount::where('tenant_id', $tenant->id)->count());
        $this->assertDatabaseCount('network_operation_logs', 0);
        $this->assertDatabaseHas('network_discovery_audits', [
            'action' => 'ADOPTION',
            'initiated_by_user_id' => $user->id,
        ]);
    }

    public function test_adoption_creates_a_local_account_with_null_secret_and_no_provisioning(): void
    {
        [$tenant, $user, $router, $resource, $connection] = $this->discoveredFixture();

        $this->actingAs($user)->post(route('network.discovery.adopt', $resource), [
            'customer_connection_id' => $connection->id,
        ])->assertRedirect();

        $account = $connection->fresh()->networkAccount;
        $this->assertNotNull($account);
        $this->assertSame('pppoe-yusuf', $account->username);
        $this->assertNull($account->encrypted_secret);
        $this->assertNull($connection->fresh()->provisioned_at);
        $this->assertSame('pending', $connection->fresh()->status);
        $this->assertDatabaseCount('network_operation_logs', 0);
    }

    public function test_cross_tenant_resource_and_connection_are_rejected(): void
    {
        [$tenant, $user, $router, $resource] = $this->discoveredFixture();
        $foreignTenant = Tenant::factory()->create();
        $foreignRouter = Router::factory()->for($foreignTenant)->create();
        $foreignConnection = $this->connection($foreignTenant, $foreignRouter);

        $this->actingAs($user)->post(route('network.discovery.adopt', $resource), [
            'customer_connection_id' => $foreignConnection->id,
        ])->assertForbidden();

        $foreignUser = User::factory()->for($foreignTenant)->create();
        $this->actingAs($foreignUser)->post(route('network.discovery.adopt', $resource), [
            'customer_connection_id' => $foreignConnection->id,
        ])->assertForbidden();
    }

    public function test_conflicting_adoption_is_rejected_without_overwriting_existing_mapping(): void
    {
        [$tenant, $user, $router, $resource, $connection] = $this->discoveredFixture();
        $otherConnection = $this->connection($tenant, $router);
        $this->actingAs($user)->post(route('network.discovery.adopt', $resource), [
            'customer_connection_id' => $connection->id,
        ])->assertRedirect();

        $secondResource = $resource->replicate();
        $secondResource->fill([
            'fingerprint' => hash('sha256', 'second-resource'),
            'external_ref' => 'pppoe-yusuf-2',
            'name' => 'pppoe-yusuf',
            'management_state' => 'DISCOVERED',
        ]);
        $secondResource->save();

        $this->actingAs($user)->post(route('network.discovery.adopt', $secondResource), [
            'customer_connection_id' => $otherConnection->id,
        ])->assertStatus(422);

        $this->assertSame($connection->id, $resource->fresh()->customer_connection_id);
        $this->assertNull($secondResource->fresh()->customer_connection_id);
        $this->assertSame(0, NetworkOperationLog::count());
    }

    public function test_repeat_discovery_preserves_adoption_and_reconciliation_becomes_matched(): void
    {
        [$tenant, $user, $router, $resource, $connection] = $this->discoveredFixture();
        $this->actingAs($user)->post(route('network.discovery.adopt', $resource), [
            'customer_connection_id' => $connection->id,
        ])->assertRedirect();

        $this->fakeDiscovery();
        $this->actingAs($user)->post(route('network.discovery.run', $router))->assertRedirect();

        $fresh = $resource->fresh();
        $this->assertSame('ADOPTED', $fresh->management_state);
        $this->assertSame($connection->id, $fresh->customer_connection_id);
        $this->assertSame('MATCHED', app(NetworkReconciliationService::class)->reconcile($router)->firstWhere('resource.id', $fresh->id)['status']);
    }

    public function test_unadopt_reverses_only_local_relationships_and_preserves_audit_history(): void
    {
        [$tenant, $user, $router, $resource, $connection] = $this->discoveredFixture();
        $this->actingAs($user)->post(route('network.discovery.adopt', $resource), [
            'customer_connection_id' => $connection->id,
        ])->assertRedirect();
        $accountId = $connection->fresh()->network_account_id;

        $this->actingAs($user)->post(route('network.discovery.unadopt', $resource), [
            'reason' => 'Operator correction',
        ])->assertRedirect();

        $this->assertSame('DISCOVERED', $resource->fresh()->management_state);
        $this->assertNull($resource->fresh()->customer_connection_id);
        $this->assertNull($resource->fresh()->network_account_id);
        $this->assertNull($connection->fresh()->network_account_id);
        $this->assertDatabaseHas('network_accounts', ['id' => $accountId]);
        $this->assertDatabaseHas('network_discovery_audits', ['action' => 'UNADOPTION']);
        $this->assertDatabaseCount('network_operation_logs', 0);
    }

    public function test_adopted_connection_is_monitorable_but_billing_cannot_mutate_it(): void
    {
        [$tenant, $user, $router, $resource, $connection] = $this->discoveredFixture();
        $this->actingAs($user)->post(route('network.discovery.adopt', $resource), [
            'customer_connection_id' => $connection->id,
        ])->assertRedirect();
        $connection->refresh();
        $invoice = Invoice::factory()->for($tenant)->for($connection->customer)->for($connection)->create([
            'status' => 'overdue',
            'total' => 200000,
            'paid_amount' => 0,
        ]);

        $this->assertSame(0, app(ProcessOverdueBilling::class)->handle($tenant->id, $user));
        $this->assertSame('pending', $connection->fresh()->status);
        $this->assertSame(0, NetworkOperationLog::count());
    }

    public function test_bulk_review_accepts_exactly_fifty_unique_individual_queue_ids(): void
    {
        [$tenant, $user, $router] = $this->tenantRouter();
        $ids = collect(range(1, 50))->map(function (int $index) use ($tenant, $router) {
            return DiscoveredNetworkResource::create([
                'tenant_id' => $tenant->id,
                'router_id' => $router->id,
                'resource_type' => 'queue',
                'external_ref' => 'queue-'.$index,
                'name' => 'subscriber-'.$index,
                'management_state' => 'DISCOVERED',
                'fingerprint' => hash('sha256', 'queue-'.$index),
                'normalized_data' => ['target' => '10.10.'.intdiv($index - 1, 254).'.'.(($index - 1) % 254 + 1).'/32'],
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ])->id;
        });

        $this->actingAs($user)
            ->post(route('network.discovery.bulk-review'), ['resource_ids' => $ids->all()])
            ->assertOk()
            ->assertViewIs('network.discovery-bulk-review')
            ->assertViewHas('resources', fn ($resources) => $resources->count() === 50);
    }

    public function test_bulk_review_rejects_more_than_fifty_unique_ids(): void
    {
        [$tenant, $user, $router] = $this->tenantRouter();
        $ids = collect(range(1, 51))->map(function (int $index) use ($tenant, $router) {
            return DiscoveredNetworkResource::create([
                'tenant_id' => $tenant->id,
                'router_id' => $router->id,
                'resource_type' => 'queue',
                'external_ref' => 'queue-'.$index,
                'name' => 'subscriber-'.$index,
                'management_state' => 'DISCOVERED',
                'fingerprint' => hash('sha256', 'queue-'.$index),
                'normalized_data' => ['target' => '10.11.'.intdiv($index - 1, 254).'.'.(($index - 1) % 254 + 1).'/32'],
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ])->id;
        });

        $this->actingAs($user)
            ->from(route('network.discovery.index', ['type' => 'queue']))
            ->post(route('network.discovery.bulk-review'), ['resource_ids' => $ids->all()])
            ->assertStatus(422)
            ->assertSee('Select no more than 50 identities per bulk operation.');
    }

    public function test_bulk_review_deduplicates_duplicate_ids_before_limit_check(): void
    {
        [$tenant, $user, $router] = $this->tenantRouter();
        $resource = DiscoveredNetworkResource::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'resource_type' => 'queue',
            'external_ref' => 'queue-duplicate-check',
            'name' => 'subscriber-duplicate-check',
            'management_state' => 'DISCOVERED',
            'fingerprint' => hash('sha256', 'queue-duplicate-check'),
            'normalized_data' => ['target' => '10.12.0.1/32'],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('network.discovery.bulk-review'), ['resource_ids' => [$resource->id, $resource->id, (string) $resource->id]])
            ->assertOk()
            ->assertViewIs('network.discovery-bulk-review')
            ->assertViewHas('resources', fn ($resources) => $resources->count() === 1);
    }

    private function discoveredFixture(): array
    {
        [$tenant, $user, $router] = $this->tenantRouter();
        $this->fakeDiscovery();
        $this->actingAs($user)->post(route('network.discovery.run', $router))->assertRedirect();
        $resource = DiscoveredNetworkResource::where('tenant_id', $tenant->id)->where('resource_type', 'pppoe_account')->firstOrFail();
        $connection = $this->connection($tenant, $router);

        return [$tenant, $user, $router, $resource, $connection];
    }

    private function connection(Tenant $tenant, Router $router): CustomerConnection
    {
        return CustomerConnection::factory()
            ->for(Customer::factory()->for($tenant))
            ->for(InternetPackage::factory()->for($tenant))
            ->for($router)
            ->create(['tenant_id' => $tenant->id]);
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
                return new DiscoveryResult(true, 'Read-only discovery complete', null, [
                    'provider' => 'fake',
                    'router_ref' => (string) $router->id,
                    'discovered_at' => now()->toIso8601String(),
                    'snapshot' => [
                        'device' => ['name' => 'CORE-01', 'routeros_version' => '6.49.13'],
                        'profiles' => [['external_ref' => '20m', 'name' => 'HOME-20M']],
                        'accounts' => [[
                            'external_ref' => 'pppoe-yusuf',
                            'username' => 'pppoe-yusuf',
                            'profile' => 'HOME-20M',
                            'enabled' => true,
                            'password' => 'never-return',
                        ]],
                        'address_pools' => [],
                        'queues' => [],
                    ],
                ]);
            }
        });
    }
}
