<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\DeviceObservation;
use App\Models\HealthObservation;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_replaces_embedded_topology_with_persisted_analytics_and_keeps_topology_route(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create(['monitoring_state' => 'online']);
        HealthObservation::factory()->for($tenant)->create([
            'subject_type' => 'router',
            'subject_id' => $router->id,
            'health_state' => 'online',
            'reachable' => true,
            'online' => true,
            'observed_at' => now(),
            'provider' => 'engine',
            'metadata' => ['version' => '6.49.13', 'cpu_load_percent' => 27, 'memory_used_percent' => 24],
        ]);
        $static = Customer::factory()->for($tenant)->create(['name' => 'Static Customer']);
        $hotspot = Customer::factory()->for($tenant)->create(['name' => 'Hotspot Customer']);
        CustomerConnection::factory()->for($static)->for($router)->create(['tenant_id' => $tenant->id, 'metadata' => ['access_mode' => 'static_ip', 'network_mechanism' => 'simple_queue', 'network_identity' => '10.0.0.2/32']]);
        CustomerConnection::factory()->for($hotspot)->for($router)->create(['tenant_id' => $tenant->id, 'metadata' => ['access_mode' => 'hotspot', 'network_mechanism' => 'hotspot', 'network_identity' => 'alice']]);
        DeviceObservation::create(['tenant_id' => $tenant->id, 'router_id' => $router->id, 'customer_connection_id' => null, 'source' => 'arp', 'network_identity' => '10.0.0.2', 'ip_address' => '10.0.0.2', 'mac_address' => 'AA:AA:AA:AA:AA:01', 'first_seen_at' => now(), 'last_seen_at' => now(), 'metadata' => []]);
        DeviceObservation::create(['tenant_id' => $tenant->id, 'router_id' => $router->id, 'customer_connection_id' => $hotspot->connections()->value('id'), 'source' => 'hotspot_active', 'network_identity' => 'alice', 'ip_address' => '10.0.0.3', 'mac_address' => 'AA:AA:AA:AA:AA:02', 'first_seen_at' => now(), 'last_seen_at' => now(), 'metadata' => []]);

        $response = $this->actingAs($user)->get('/dashboard')->assertOk();

        $response->assertSee('Analytics')
            ->assertSee('STATIC IP')
            ->assertSee('HOTSPOT')
            ->assertSee('Observed devices')
            ->assertSee('Linked observations')
            ->assertSee('Unmapped observations')
            ->assertSee('Online')
            ->assertSee('No recent data')
            ->assertSee('Traffic Intelligence')
            ->assertDontSee('dashboard-topology-vue')
            ->assertDontSee('Network Topology');

        $this->actingAs($user)->get('/network/topology')->assertOk();
    }

    private function tenantWithUser(): array
    {
        $tenant = Tenant::factory()->create();
        return [$tenant, User::factory()->for($tenant)->create()];
    }
}