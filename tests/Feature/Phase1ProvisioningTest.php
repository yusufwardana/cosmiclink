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
use Tests\TestCase;

class Phase1ProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_and_package_crud_is_tenant_scoped(): void
    {
        [$tenantA, $userA] = $this->tenantWithUser('Tenant A');
        [$tenantB] = $this->tenantWithUser('Tenant B');

        $this->actingAs($userA)->post(route('customers.store'), [
            'name' => 'Budi Santoso', 'phone' => '08123456789', 'status' => 'active',
        ])->assertRedirect(route('customers.index'));
        $customer = Customer::where('tenant_id', $tenantA->id)->firstOrFail();
        $this->assertSame('CL'.str_pad((string) $customer->id, 6, '0', STR_PAD_LEFT), $customer->customer_code);

        $this->actingAs($userA)->put(route('customers.update', $customer), [
            'name' => 'Budi Updated', 'status' => 'inactive',
        ])->assertRedirect();
        $this->assertSame('inactive', $customer->fresh()->status);

        $package = InternetPackage::factory()->for($tenantB)->create();
        $this->actingAs($userA)->get(route('customers.show', $customer))->assertOk()->assertSee('Budi Updated');
        $this->actingAs($userA)->get(route('packages.show', $package))->assertForbidden();
        $this->actingAs($userA)->put(route('packages.update', $package), [
            'name' => 'Hijacked', 'code' => 'BAD', 'download_mbps' => 1, 'upload_mbps' => 1,
            'monthly_price' => 1, 'network_profile' => 'BAD', 'status' => 'active',
        ])->assertForbidden();
    }

    public function test_customer_connection_cannot_attach_foreign_resources(): void
    {
        [$tenantA, $userA] = $this->tenantWithUser('Tenant A');
        [$tenantB] = $this->tenantWithUser('Tenant B');
        $customer = Customer::factory()->for($tenantA)->create();
        $foreignPackage = InternetPackage::factory()->for($tenantB)->create();
        $foreignRouter = Router::factory()->for($tenantB)->create();

        $this->actingAs($userA)->post(route('customers.connections.store', $customer), [
            'internet_package_id' => $foreignPackage->id, 'router_id' => $foreignRouter->id,
        ])->assertForbidden();
        $this->assertDatabaseCount('customer_connections', 0);
    }

    public function test_zero_touch_provisioning_creates_account_with_package_profile(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $customer = Customer::factory()->for($tenant)->create();
        $package = InternetPackage::factory()->for($tenant)->create(['network_profile' => 'HOME-20M']);
        $router = Router::factory()->for($tenant)->create();

        $this->actingAs($user)->post(route('customers.connections.store', $customer), [
            'internet_package_id' => $package->id, 'router_id' => $router->id,
        ])->assertRedirect(route('customers.show', $customer));
        $connection = $customer->connections()->firstOrFail();

        $this->actingAs($user)->post(route('connections.provision', $connection))->assertRedirect();
        $connection->refresh();
        $account = $connection->networkAccount()->firstOrFail();
        $this->assertSame('active', $connection->status);
        $this->assertNotNull($connection->provisioned_at);
        $this->assertSame('HOME-20M', $account->profile);
        $this->assertSame($router->id, $account->router_id);
        $this->assertDatabaseHas('network_operation_logs', ['operation' => 'CREATE_PPPOE', 'status' => 'success']);
    }

    public function test_failed_provisioning_can_retry_without_duplicate_account_and_secret_is_protected(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $customer = Customer::factory()->for($tenant)->create();
        $package = InternetPackage::factory()->for($tenant)->create();
        $router = Router::factory()->for($tenant)->create(['status' => 'unavailable']);
        $connection = CustomerConnection::factory()->for($customer)->for($package)->for($router)->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->post(route('connections.provision', $connection))->assertRedirect();
        $connection->refresh();
        $this->assertSame('failed', $connection->status);
        $this->assertSame('ROUTER_UNAVAILABLE', $connection->failure_code);
        $this->assertNotNull($connection->failed_at);
        $this->assertStringNotContainsString('secret', json_encode(NetworkOperationLog::latest()->first()->toArray()));

        $router->update(['status' => 'available']);
        $this->actingAs($user)->post(route('connections.provision', $connection))->assertRedirect();
        $this->assertSame('active', $connection->fresh()->status);
        $this->assertSame(1, NetworkAccount::where('tenant_id', $tenant->id)->count());
    }

    public function test_repeated_provisioning_is_idempotent(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $connection = CustomerConnection::factory()->for(Customer::factory()->for($tenant))->for(InternetPackage::factory()->for($tenant))->for(Router::factory()->for($tenant))->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->post(route('connections.provision', $connection));
        $this->actingAs($user)->post(route('connections.provision', $connection));

        $this->assertSame(1, NetworkAccount::where('tenant_id', $tenant->id)->count());
    }

    public function test_provisioning_logs_are_traced_to_the_connection_on_success_and_retry(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $connection = CustomerConnection::factory()->for(Customer::factory()->for($tenant))->for(InternetPackage::factory()->for($tenant))->for(Router::factory()->for($tenant)->state(['status' => 'unavailable']))->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->post(route('connections.provision', $connection));
        $connection->router->update(['status' => 'available']);
        $this->actingAs($user)->post(route('connections.provision', $connection));

        $logs = NetworkOperationLog::where('operation', 'CREATE_PPPOE')->get();
        $this->assertCount(2, $logs);
        $this->assertTrue($logs->every(fn (NetworkOperationLog $log) => $log->customer_connection_id === $connection->id));
        $this->assertSame(['failed', 'success'], $logs->pluck('status')->all());
    }

    public function test_customer_360_only_shows_operations_for_that_customers_connections(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create();
        $package = InternetPackage::factory()->for($tenant)->create();
        $budi = Customer::factory()->for($tenant)->create(['name' => 'Budi Santoso']);
        $siti = Customer::factory()->for($tenant)->create(['name' => 'Siti Rahma']);
        $budiConnection = CustomerConnection::factory()->for($budi)->for($package)->for($router)->create(['tenant_id' => $tenant->id]);
        $sitiConnection = CustomerConnection::factory()->for($siti)->for($package)->for($router)->create(['tenant_id' => $tenant->id]);
        NetworkOperationLog::factory()->for($tenant)->for($router)->create(['operation' => 'BUDI_OPERATION', 'customer_connection_id' => $budiConnection->id]);
        NetworkOperationLog::factory()->for($tenant)->for($router)->create(['operation' => 'SITI_OPERATION', 'customer_connection_id' => $sitiConnection->id]);

        $this->actingAs($user)->get(route('customers.show', $budi))->assertSee('BUDI_OPERATION')->assertDontSee('SITI_OPERATION');
    }

    private function tenantWithUser(string $name = 'DemoNet ISP'): array
    {
        $tenant = Tenant::factory()->create(['name' => $name]);

        return [$tenant, User::factory()->for($tenant)->create()];
    }
}
