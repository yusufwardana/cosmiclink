<?php

namespace Tests\Feature;

use App\Models\HealthObservation;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NetworkMapTest extends TestCase
{
    use RefreshDatabase;

    private function tenantWithUser(string $role = 'admin'): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create(['role' => $role]);

        return [$tenant, $user];
    }

    public function test_authenticated_network_map_returns_only_coordinate_backed_routers_with_real_health(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $located = Router::factory()->for($tenant)->create([
            'name' => 'MikroTik hEX',
            'latitude' => -7.7956,
            'longitude' => 110.3695,
        ]);
        Router::factory()->for($tenant)->create(['name' => 'Unpositioned router']);
        HealthObservation::factory()->create([
            'tenant_id' => $tenant->id,
            'subject_type' => 'router',
            'subject_id' => $located->id,
            'health_state' => 'online',
            'online' => true,
            'reachable' => true,
            'observed_at' => now(),
            'metadata' => [
                'version' => '6.49.13 (long-term)',
                'board' => 'hEX',
                'cpu_load_percent' => 17,
                'memory_used_percent' => 28,
            ],
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/network-map')
            ->assertOk()
            ->assertJsonPath('data.routers.0.id', $located->id)
            ->assertJsonPath('data.routers.0.name', 'MikroTik hEX')
            ->assertJsonPath('data.routers.0.latitude', -7.7956)
            ->assertJsonPath('data.routers.0.longitude', 110.3695)
            ->assertJsonPath('data.routers.0.monitoring_state', 'online')
            ->assertJsonPath('data.routers.0.routeros_version', '6.49.13 (long-term)')
            ->assertJsonPath('data.routers.0.board_name', 'hEX')
            ->assertJsonCount(1, 'data.routers');
    }

    public function test_network_map_is_tenant_isolated(): void
    {
        [$tenantA, $userA] = $this->tenantWithUser('admin');
        [$tenantB] = $this->tenantWithUser('admin');
        Router::factory()->for($tenantA)->create(['latitude' => 1, 'longitude' => 2]);
        Router::factory()->for($tenantB)->create(['latitude' => 3, 'longitude' => 4]);

        $this->actingAs($userA)
            ->getJson('/api/v1/network-map')
            ->assertOk()
            ->assertJsonCount(1, 'data.routers')
            ->assertJsonPath('data.routers.0.latitude', 1);
    }

    public function test_network_map_exposes_unlocated_customers_without_assigning_coordinates(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $customer = \App\Models\Customer::factory()->for($tenant)->create(['name' => 'MAHLOR', 'latitude' => null, 'longitude' => null]);

        $this->actingAs($user)
            ->getJson('/api/v1/network-map')
            ->assertOk()
            ->assertJsonPath('data.unlocated_customers.0.id', $customer->id)
            ->assertJsonPath('data.unlocated_customers.0.name', 'MAHLOR')
            ->assertJsonPath('data.unlocated_customers.0.latitude', null)
            ->assertJsonPath('data.unlocated_customers.0.longitude', null);
    }

    public function test_router_location_update_validates_coordinates_and_changes_only_location_fields(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create(['name' => 'Before', 'host' => 'router.local']);

        $this->actingAs($user)
            ->putJson("/api/v1/network-map/routers/{$router->id}/location", ['latitude' => 91, 'longitude' => 181])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);

        $this->actingAs($user)
            ->putJson("/api/v1/network-map/routers/{$router->id}/location", ['latitude' => -7.7956, 'longitude' => 110.3695])
            ->assertOk()
            ->assertJsonPath('data.latitude', -7.7956)
            ->assertJsonPath('data.longitude', 110.3695);

        $fresh = $router->fresh();
        $this->assertSame('Before', $fresh->name);
        $this->assertSame('router.local', $fresh->host);
        $this->assertEquals(-7.7956, (float) $fresh->latitude);
        $this->assertEquals(110.3695, (float) $fresh->longitude);
    }

    public function test_router_location_update_cannot_cross_tenant_boundary(): void
    {
        [$tenantA, $userA] = $this->tenantWithUser();
        [$tenantB] = $this->tenantWithUser();
        $router = Router::factory()->for($tenantB)->create();

        $this->actingAs($userA)
            ->putJson("/api/v1/network-map/routers/{$router->id}/location", ['latitude' => 1, 'longitude' => 2])
            ->assertForbidden();
    }

    public function test_customer_location_update_is_tenant_scoped_and_location_only(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $customer = \App\Models\Customer::factory()->for($tenant)->create(['name' => 'PC']);

        $this->actingAs($user)->putJson("/api/v1/network-map/customers/{$customer->id}/location", ['latitude' => -7.8938, 'longitude' => 110.8485])->assertOk();

        $fresh = $customer->fresh();
        $this->assertEquals(-7.8938, (float) $fresh->latitude);
        $this->assertEquals(110.8485, (float) $fresh->longitude);
        $this->assertSame('PC', $fresh->name);
        $this->assertSame('active', $fresh->status);
    }
}