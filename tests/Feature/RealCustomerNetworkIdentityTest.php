<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\DiscoveredNetworkResource;
use App\Models\InternetPackage;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealCustomerNetworkIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create();
        $router = Router::factory()->for($tenant)->create(['name' => 'MikroTik hEX']);
        $resource = DiscoveredNetworkResource::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'resource_type' => 'queue',
            'external_ref' => '*queue-delan',
            'name' => 'Delan',
            'management_state' => 'DISCOVERED',
            'fingerprint' => hash('sha256', 'delan'),
            'normalized_data' => ['name' => 'Delan', 'target' => '10.10.12.41/32'],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        return [$tenant, $user, $router, $resource];
    }

    public function test_explicit_simple_queue_discovery_identity_creates_an_adopted_customer_without_router_mutation(): void
    {
        [$tenant, $user, $router, $resource] = $this->fixture();

        $this->actingAs($user)->post(route('network.discovery.customers.store', $resource), [
            'name' => 'Delan',
            'status' => 'active',
        ])->assertRedirect();

        $customer = Customer::where('tenant_id', $tenant->id)->firstOrFail();
        $connection = $customer->connections()->with('router')->firstOrFail();
        $resource->refresh();

        $this->assertSame('Delan', $customer->name);
        $this->assertSame('active', $customer->status);
        $this->assertSame($router->id, $connection->router_id);
        $this->assertSame('simple_queue', $connection->metadata['connection_mode']);
        $this->assertSame('10.10.12.41/32', $connection->metadata['network_identity']);
        $this->assertSame('ADOPTED', $resource->management_state);
        $this->assertSame($connection->id, $resource->customer_connection_id);
        $this->assertNull($customer->latitude);
        $this->assertNull($customer->longitude);
        $this->assertSame('available', $router->fresh()->status);
    }

    public function test_simple_queue_aggregate_target_cannot_be_adopted_as_a_customer(): void
    {
        [, $user, , $resource] = $this->fixture();
        $resource->update([
            'name' => 'ALL TRAFICK',
            'normalized_data' => ['target' => '10.10.11.0/24,10.10.12.0/24'],
        ]);

        $this->actingAs($user)->post(route('network.discovery.customers.store', $resource), ['name' => 'ALL TRAFICK', 'status' => 'active'])
            ->assertUnprocessable();

        $this->assertDatabaseCount('customers', 0);
        $this->assertSame('DISCOVERED', $resource->fresh()->management_state);
    }

    public function test_duplicate_simple_queue_adoption_is_rejected(): void
    {
        [$tenant, $user, $router, $resource] = $this->fixture();
        $this->actingAs($user)->post(route('network.discovery.customers.store', $resource), ['name' => 'Delan', 'status' => 'active'])->assertRedirect();

        $this->actingAs($user)->post(route('network.discovery.customers.store', $resource), ['name' => 'Delan Again', 'status' => 'active'])
            ->assertUnprocessable();

        $this->assertSame(1, Customer::where('tenant_id', $tenant->id)->count());
        $this->assertSame('ADOPTED', $resource->fresh()->management_state);
        $this->assertSame($router->id, CustomerConnection::firstOrFail()->router_id);
    }

    public function test_customer_location_is_nullable_and_validated(): void
    {
        [$tenant, $user] = $this->fixture();
        $customer = Customer::factory()->for($tenant)->create();

        $this->actingAs($user)->put(route('customers.update', $customer), [
            'name' => $customer->name,
            'status' => 'active',
            'latitude' => 91,
            'longitude' => 181,
        ])->assertSessionHasErrors(['latitude', 'longitude']);

        $this->actingAs($user)->put(route('customers.update', $customer), [
            'name' => $customer->name,
            'status' => 'active',
            'latitude' => 7.893742,
            'longitude' => 110.848658,
        ])->assertRedirect();

        $this->assertNotNull($customer->fresh()->location_updated_at);
        $this->assertEquals(7.893742, (float) $customer->fresh()->latitude);
        $this->assertEquals(110.848658, (float) $customer->fresh()->longitude);
    }

    public function test_customer_edit_form_exposes_location_picker_without_persisting_coordinates(): void
    {
        [$tenant, $user] = $this->fixture();
        $customer = Customer::factory()->for($tenant)->create(['latitude' => null, 'longitude' => null]);

        $this->actingAs($user)
            ->get(route('customers.edit', $customer))
            ->assertOk()
            ->assertSee('customer-location-picker')
            ->assertSee('data-default-latitude', false)
            ->assertSee('data-default-longitude', false);

        $this->assertNull($customer->fresh()->latitude);
        $this->assertNull($customer->fresh()->longitude);
    }

    public function test_dashboard_discovery_resource_count_uses_persisted_discovery_inventory(): void
    {
        [$tenant, $user, $router, $resource] = $this->fixture();

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee((string) DiscoveredNetworkResource::where('tenant_id', $tenant->id)->count())
            ->assertSee('persisted Discovery resources');
    }
}