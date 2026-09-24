<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyCustomerConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_customer_creates_a_simple_queue_monthly_connection_without_router_mutation(): void
    {
        [$tenant, $user, $router] = $this->fixture();

        $this->actingAs($user)->post(route('customers.store'), [
            'name' => 'PC Monthly',
            'status' => 'active',
            'router_id' => $router->id,
            'connection_mode' => 'simple_queue',
            'network_identity' => '10.10.12.50/32',
            'latitude' => -7.893352,
            'longitude' => 110.848972,
        ])->assertRedirect(route('customers.index'));

        $customer = Customer::where('tenant_id', $tenant->id)->where('name', 'PC Monthly')->firstOrFail();
        $connection = $customer->connections()->firstOrFail();

        $this->assertSame($router->id, $connection->router_id);
        $this->assertSame('simple_queue', $connection->metadata['connection_mode']);
        $this->assertSame('10.10.12.50/32', $connection->metadata['network_identity']);
        $this->assertEquals(-7.893352, (float) $customer->latitude);
        $this->assertEquals(110.848972, (float) $customer->longitude);
        $this->assertDatabaseCount('network_operation_logs', 0);
    }

    public function test_create_customer_creates_a_hotspot_monthly_connection_with_stable_username(): void
    {
        [$tenant, $user, $router] = $this->fixture();

        $this->actingAs($user)->post(route('customers.store'), [
            'name' => 'Feri',
            'status' => 'active',
            'router_id' => $router->id,
            'connection_mode' => 'hotspot',
            'network_identity' => ' feri-hotspot ',
        ])->assertRedirect(route('customers.index'));

        $customer = Customer::where('tenant_id', $tenant->id)->where('name', 'Feri')->firstOrFail();
        $connection = $customer->connections()->firstOrFail();

        $this->assertSame('hotspot', $connection->metadata['connection_mode']);
        $this->assertSame('feri-hotspot', $connection->metadata['network_identity']);
        $this->assertDatabaseCount('network_operation_logs', 0);
    }

    public function test_duplicate_hotspot_identity_on_same_tenant_and_router_is_rejected_without_orphan_customer(): void
    {
        [$tenant, $user, $router] = $this->fixture();
        $existing = Customer::factory()->for($tenant)->create(['name' => 'Feri']);
        CustomerConnection::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $existing->id,
            'router_id' => $router->id,
            'status' => 'active',
            'metadata' => ['connection_mode' => 'hotspot', 'network_identity' => 'feri-hotspot'],
        ]);

        $this->actingAs($user)->post(route('customers.store'), [
            'name' => 'Feri Duplicate',
            'status' => 'active',
            'router_id' => $router->id,
            'connection_mode' => 'hotspot',
            'network_identity' => 'feri-hotspot',
        ])->assertSessionHasErrors('network_identity');

        $this->assertDatabaseMissing('customers', ['tenant_id' => $tenant->id, 'name' => 'Feri Duplicate']);
        $this->assertSame(1, CustomerConnection::where('tenant_id', $tenant->id)->count());
    }

    public function test_simple_queue_aggregate_target_is_rejected(): void
    {
        [, $user, $router] = $this->fixture();

        $this->actingAs($user)->post(route('customers.store'), [
            'name' => 'Aggregate Customer',
            'status' => 'active',
            'router_id' => $router->id,
            'connection_mode' => 'simple_queue',
            'network_identity' => '10.10.12.0/24',
        ])->assertSessionHasErrors('network_identity');

        $this->assertDatabaseMissing('customers', ['name' => 'Aggregate Customer']);
    }

    public function test_customer_cannot_reference_a_router_from_another_tenant(): void
    {
        [$tenant, $user] = $this->fixture();
        $foreignTenant = Tenant::factory()->create();
        $foreignRouter = Router::factory()->for($foreignTenant)->create();

        $this->actingAs($user)->post(route('customers.store'), [
            'name' => 'Foreign Router Customer',
            'status' => 'active',
            'router_id' => $foreignRouter->id,
            'connection_mode' => 'hotspot',
            'network_identity' => 'foreign-hotspot',
        ])->assertForbidden();

        $this->assertDatabaseMissing('customers', ['tenant_id' => $tenant->id, 'name' => 'Foreign Router Customer']);
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create(['role' => 'admin']);
        $router = Router::factory()->for($tenant)->create(['name' => 'MikroTik hEX']);

        return [$tenant, $user, $router];
    }
}