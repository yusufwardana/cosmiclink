<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\InternetPackage;
use App\Models\NetworkAccount;
use App\Models\NetworkOperationLog;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Prerequisite for Phase 6I: role authorization for network operations.
 *
 * Tenant membership is the data-isolation boundary, not the network-operation
 * authority. These tests pin the operator/customer boundary on every surface
 * that dispatches a device operation through the network driver or agent, and
 * pin the surrounding invariants: tenancy still denies, unknown roles fail
 * closed, guests are still challenged, non-network customer screens keep
 * working, and a denial never reaches transport.
 */
class NetworkOperatorAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** A tenant member that is not a network operator. */
    private const CUSTOMER_ROLE = 'customer';

    public function test_customer_role_user_cannot_run_router_level_network_operations(): void
    {
        [$tenant, $user] = $this->tenantWithUser(self::CUSTOMER_ROLE);
        $router = Router::factory()->for($tenant)->create();

        $this->actingAs($user)->post(route('routers.test', $router))->assertForbidden();
        $this->actingAs($user)->post(route('network.accounts.store'), [
            'router_id' => $router->id,
            'username' => 'cust001',
            'profile' => 'HOME-10M',
        ])->assertForbidden();
        $this->actingAs($user)->post(route('network.discovery.run', $router))->assertForbidden();

        $this->assertNull($router->fresh()->last_seen_at);
        $this->assertDatabaseCount('network_accounts', 0);
        $this->assertDatabaseCount('network_operation_logs', 0);
    }

    public function test_customer_role_user_cannot_mutate_network_accounts(): void
    {
        [$tenant, $operator] = $this->tenantWithUser('admin');
        $router = Router::factory()->for($tenant)->create();
        $this->actingAs($operator)->post(route('network.accounts.store'), [
            'router_id' => $router->id,
            'username' => 'cust001',
            'profile' => 'HOME-10M',
        ])->assertRedirect();
        $account = NetworkAccount::query()->where('username', 'cust001')->firstOrFail();
        $logsBefore = NetworkOperationLog::query()->count();

        $customer = User::factory()->for($tenant)->create(['role' => self::CUSTOMER_ROLE]);

        $this->actingAs($customer)->post(route('network.accounts.disable', $account))->assertForbidden();
        $this->actingAs($customer)->post(route('network.accounts.enable', $account))->assertForbidden();
        $this->actingAs($customer)->put(route('network.accounts.profile', $account), [
            'profile' => 'HOME-20M',
        ])->assertForbidden();
        $this->actingAs($customer)->post(route('network.accounts.disconnect', $account))->assertForbidden();

        $this->assertSame('active', $account->fresh()->status);
        $this->assertSame('HOME-10M', $account->fresh()->profile);
        $this->assertSame($logsBefore, NetworkOperationLog::query()->count(), 'Denial must not dispatch an operation.');
    }

    public function test_customer_role_user_cannot_provision_a_connection(): void
    {
        [$tenant, $user] = $this->tenantWithUser(self::CUSTOMER_ROLE);
        $connection = $this->pendingConnection($tenant);

        $this->actingAs($user)->post(route('connections.provision', $connection))->assertForbidden();

        $this->assertSame('pending', $connection->fresh()->status);
        $this->assertDatabaseCount('network_accounts', 0);
        $this->assertDatabaseCount('network_operation_logs', 0);
    }

    public function test_customer_role_user_cannot_trigger_device_monitoring_probes(): void
    {
        [$tenant, $user] = $this->tenantWithUser(self::CUSTOMER_ROLE);
        $router = Router::factory()->for($tenant)->create();
        $connection = $this->pendingConnection($tenant);

        $this->actingAs($user)->post(route('monitoring.check'))->assertForbidden();
        $this->actingAs($user)->post(route('monitoring.routers.observe', $router))->assertForbidden();
        $this->actingAs($user)->post(route('monitoring.connections.observe', $connection))->assertForbidden();
        $this->actingAs($user)->postJson(route('api.v1.monitoring.check'))->assertForbidden();

        $this->assertDatabaseCount('health_observations', 0);
    }

    public function test_customer_role_denial_never_reaches_the_go_transport(): void
    {
        Http::fake();
        config(['network.driver' => 'go', 'network.go.url' => 'http://127.0.0.1:8787', 'network.go.token' => 'engine-secret']);
        [$tenant, $user] = $this->tenantWithUser(self::CUSTOMER_ROLE);
        $router = Router::factory()->for($tenant)->create();

        $this->actingAs($user)->post(route('network.accounts.store'), [
            'router_id' => $router->id,
            'username' => 'cust001',
            'profile' => 'HOME-10M',
        ])->assertForbidden();
        $this->actingAs($user)->post(route('routers.test', $router))->assertForbidden();
        $this->actingAs($user)->post(route('network.discovery.run', $router))->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('network_operation_logs', 0);
    }

    public function test_operator_roles_can_still_perform_network_operations_with_the_fake_driver(): void
    {
        foreach (['admin', 'owner'] as $role) {
            [$tenant, $user] = $this->tenantWithUser($role, 'Operator '.$role);
            $router = Router::factory()->for($tenant)->create();

            $this->actingAs($user)->post(route('routers.test', $router))
                ->assertSessionHas('status', 'Connection test successful.');
            $this->actingAs($user)->post(route('network.accounts.store'), [
                'router_id' => $router->id,
                'username' => 'op'.$role,
                'profile' => 'HOME-10M',
            ])->assertRedirect()->assertSessionHas('status');
            $account = NetworkAccount::query()->where('username', 'op'.$role)->firstOrFail();
            $this->actingAs($user)->post(route('network.accounts.disable', $account))->assertRedirect();
            $this->actingAs($user)->post(route('network.accounts.enable', $account))->assertRedirect();
            $this->actingAs($user)->post(route('network.accounts.disconnect', $account))->assertRedirect();
            $this->actingAs($user)->post(route('monitoring.routers.observe', $router))->assertRedirect();

            $this->assertSame('active', $account->fresh()->status);
            $this->assertNotNull($router->fresh()->last_seen_at);
        }

        $this->assertGreaterThan(0, NetworkOperationLog::query()->count());
    }

    public function test_operator_role_can_still_provision_a_connection(): void
    {
        [$tenant, $user] = $this->tenantWithUser('admin');
        $connection = $this->pendingConnection($tenant);

        $this->actingAs($user)->post(route('connections.provision', $connection))
            ->assertRedirect()->assertSessionHas('status', 'Connection provisioned.');

        $this->assertSame('active', $connection->fresh()->status);
        $this->assertDatabaseHas('network_operation_logs', ['operation' => 'CREATE_PPPOE', 'status' => 'success']);
    }

    public function test_roles_outside_the_operator_allowlist_fail_closed(): void
    {
        foreach (['', 'superadmin', 'Customer', 'CUSTOMER', 'support'] as $role) {
            [$tenant, $user] = $this->tenantWithUser($role, 'Tenant '.$role);
            $router = Router::factory()->for($tenant)->create();

            $this->actingAs($user)->post(route('routers.test', $router))->assertForbidden();
            $this->actingAs($user)->post(route('monitoring.check'))->assertForbidden();
        }

        $this->assertDatabaseCount('network_operation_logs', 0);
        $this->assertDatabaseCount('health_observations', 0);
    }

    public function test_operator_roles_are_configuration_driven(): void
    {
        [$tenant, $admin] = $this->tenantWithUser('admin', 'Config tenant');
        $router = Router::factory()->for($tenant)->create();

        config(['network.operator_roles' => ['owner']]);
        $this->actingAs($admin)->post(route('routers.test', $router))->assertForbidden();

        $owner = User::factory()->for($tenant)->create(['role' => 'owner']);
        $this->actingAs($owner)->post(route('routers.test', $router))
            ->assertSessionHas('status', 'Connection test successful.');

        config(['network.operator_roles' => ['owner', 'admin']]);
        $this->actingAs($admin)->post(route('routers.test', $router))
            ->assertSessionHas('status', 'Connection test successful.');
    }

    public function test_tenancy_still_denies_another_tenants_operator(): void
    {
        [, $operatorB] = $this->tenantWithUser('admin', 'Tenant B');
        [$tenantA] = $this->tenantWithUser('admin', 'Tenant A');
        $routerA = Router::factory()->for($tenantA)->create();

        $this->actingAs($operatorB)->post(route('network.accounts.store'), [
            'router_id' => $routerA->id,
            'username' => 'stolen',
            'profile' => 'HOME-10M',
        ])->assertForbidden();

        $connectionA = $this->pendingConnection($tenantA);
        $this->actingAs($operatorB)->post(route('connections.provision', $connectionA))->assertForbidden();

        $this->assertDatabaseCount('network_accounts', 0);
        $this->assertDatabaseCount('network_operation_logs', 0);
        $this->assertSame('pending', $connectionA->fresh()->status);
    }

    public function test_customer_role_user_keeps_non_network_customer_functionality(): void
    {
        [$tenant, $user] = $this->tenantWithUser(self::CUSTOMER_ROLE);
        $customer = Customer::factory()->for($tenant)->create();
        Router::factory()->for($tenant)->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->actingAs($user)->get(route('customers.index'))->assertOk();
        $this->actingAs($user)->get(route('customers.show', $customer))->assertOk();
        $this->actingAs($user)->get(route('packages.index'))->assertOk();
        $this->actingAs($user)->get(route('billing.invoices.index'))->assertOk();
        $this->actingAs($user)->get(route('routers.index'))->assertOk();
        $this->actingAs($user)->get(route('network.accounts.index'))->assertOk();
        $this->actingAs($user)->get(route('monitoring.index'))->assertOk();
        $this->actingAs($user)->get(route('network.logs.index'))->assertOk();
        $this->actingAs($user)->getJson(route('api.v1.customers.index'))->assertOk();
        $this->actingAs($user)->getJson(route('api.v1.customers.show', $customer))->assertOk();
    }

    public function test_customer_role_user_can_still_edit_customer_records(): void
    {
        [$tenant, $user] = $this->tenantWithUser(self::CUSTOMER_ROLE);
        $customer = Customer::factory()->for($tenant)->create();

        $this->actingAs($user)->put(route('customers.update', $customer), [
            'name' => 'Budi Updated',
            'status' => 'inactive',
        ])->assertRedirect();
        $this->assertSame('Budi Updated', $customer->fresh()->name);
    }

    public function test_guests_are_still_challenged_before_role_authorization(): void
    {
        [$tenant] = $this->tenantWithUser(self::CUSTOMER_ROLE);
        $router = Router::factory()->for($tenant)->create();

        $this->post(route('routers.test', $router))->assertRedirect(route('login'));
        $this->post(route('monitoring.check'))->assertRedirect(route('login'));
        $this->postJson(route('api.v1.monitoring.check'))->assertUnauthorized();
        $this->assertDatabaseCount('network_operation_logs', 0);
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function tenantWithUser(string $role = 'admin', string $tenantName = 'DemoNet ISP'): array
    {
        $tenant = Tenant::factory()->create(['name' => $tenantName]);
        $user = User::factory()->for($tenant)->create(['role' => $role]);

        return [$tenant, $user];
    }

    private function pendingConnection(Tenant $tenant): CustomerConnection
    {
        $customer = Customer::factory()->for($tenant)->create();
        $package = InternetPackage::factory()->for($tenant)->create(['network_profile' => 'HOME-20M']);
        $router = Router::factory()->for($tenant)->create();

        return CustomerConnection::factory()->for($customer)->for($package)->for($router)
            ->create(['tenant_id' => $tenant->id]);
    }
}
