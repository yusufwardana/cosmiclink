<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\DiscoveredNetworkResource;
use App\Models\InternetPackage;
use App\Models\NetworkDiscoveryAudit;
use App\Models\NetworkDiscoverySnapshot;
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
                return new DiscoveryResult(true, 'Read-only discovery complete', null, ['provider' => 'fake', 'router_ref' => (string) $router->id, 'discovered_at' => '2026-09-17T00:00:00Z', 'snapshot' => ['device' => ['name' => 'CORE-01'], 'profiles' => [['external_ref' => '10m', 'name' => '10M']], 'accounts' => [['external_ref' => 'existing-user-001', 'username' => 'existing-user-001', 'profile' => '10M', 'enabled' => true, 'password' => 'never-return', 'pass' => 'never-return', 'authorization' => 'never-return', 'credential' => 'never-return']], 'address_pools' => [['external_ref' => 'pppoe-pool', 'name' => 'pppoe-pool']], 'queues' => [['external_ref' => 'queue-1', 'name' => 'queue-1']]]]);
            }
        });
    }
}
