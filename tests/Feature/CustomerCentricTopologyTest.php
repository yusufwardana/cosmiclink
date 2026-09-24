<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\DeviceObservation;
use App\Models\HealthObservation;
use App\Models\InternetPackage;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCentricTopologyTest extends TestCase
{
    use RefreshDatabase;

    public function test_topology_uses_one_customer_connection_node_and_only_linked_devices(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create();
        HealthObservation::factory()->for($tenant)->create([
            'subject_type' => 'router',
            'subject_id' => $router->id,
            'health_state' => 'online',
            'reachable' => true,
            'online' => true,
            'observed_at' => now(),
            'metadata' => ['identity' => 'TP-Link', 'version' => '6.49.13', 'board' => 'hEX', 'cpu_load_percent' => 27, 'memory_used_percent' => 24],
        ]);
        $package = InternetPackage::factory()->for($tenant)->create();
        $mahlor = Customer::factory()->for($tenant)->create(['name' => 'MAHLOR']);
        $klentruk = Customer::factory()->for($tenant)->create(['name' => 'KLENTRUK']);
        $mahlorConnection = CustomerConnection::factory()->for($mahlor)->for($package)->for($router)->create([
            'tenant_id' => $tenant->id,
            'metadata' => ['connection_mode' => 'hotspot', 'access_mode' => 'hotspot', 'network_mechanism' => 'hotspot', 'network_identity' => 'MAHLOR'],
        ]);
        $klentrukConnection = CustomerConnection::factory()->for($klentruk)->for($package)->for($router)->create([
            'tenant_id' => $tenant->id,
            'metadata' => ['connection_mode' => 'simple_queue', 'access_mode' => 'static_ip', 'network_mechanism' => 'simple_queue', 'network_identity' => '10.10.12.26/32'],
        ]);
        DeviceObservation::create(['tenant_id' => $tenant->id, 'router_id' => $router->id, 'customer_connection_id' => $mahlorConnection->id, 'source' => 'hotspot_active', 'network_identity' => 'mahlor', 'ip_address' => '10.10.12.123', 'mac_address' => 'AA:AA:AA:AA:AA:01', 'first_seen_at' => now(), 'last_seen_at' => now(), 'metadata' => ['dhcp_hostname' => 'phone-a']]);
        DeviceObservation::create(['tenant_id' => $tenant->id, 'router_id' => $router->id, 'customer_connection_id' => $mahlorConnection->id, 'source' => 'hotspot_active', 'network_identity' => 'mahlor', 'ip_address' => '10.10.12.181', 'mac_address' => 'AA:AA:AA:AA:AA:02', 'first_seen_at' => now(), 'last_seen_at' => now(), 'metadata' => ['dhcp_hostname' => 'phone-b']]);
        DeviceObservation::create(['tenant_id' => $tenant->id, 'router_id' => $router->id, 'customer_connection_id' => null, 'source' => 'hotspot_active', 'network_identity' => 'unmapped', 'ip_address' => '10.10.12.200', 'mac_address' => 'AA:AA:AA:AA:AA:03', 'first_seen_at' => now(), 'last_seen_at' => now(), 'metadata' => []]);

        $response = $this->actingAs($user)->getJson('/api/v1/topology')->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data['routers']);
        $this->assertSame('online', $data['routers'][0]['monitoring_state']);
        $this->assertSame(['HOTSPOT', 'STATIC IP'], collect($data['access_mode_groups'])->pluck('label')->sort()->values()->all());
        $this->assertCount(2, $data['customer_nodes']);
        $this->assertSame(1, collect($data['customer_nodes'])->where('identity', 'MAHLOR')->count());
        $this->assertSame(1, collect($data['customer_nodes'])->where('identity', '10.10.12.26/32')->count());
        $this->assertSame(2, collect($data['device_nodes'])->where('customer_connection_id', $mahlorConnection->id)->count());
        $this->assertSame(0, collect($data['device_nodes'])->whereNull('customer_connection_id')->count());
        $this->assertSame(2, collect($data['customer_nodes'])->where('router_id', $router->id)->count());
        $this->assertSame($mahlorConnection->id, collect($data['customer_nodes'])->firstWhere('identity', 'MAHLOR')['customer_connection_id']);
        $this->assertSame($klentrukConnection->id, collect($data['customer_nodes'])->firstWhere('identity', '10.10.12.26/32')['customer_connection_id']);
    }

    private function tenantWithUser(): array
    {
        $tenant = Tenant::factory()->create();
        return [$tenant, User::factory()->for($tenant)->create()];
    }
}