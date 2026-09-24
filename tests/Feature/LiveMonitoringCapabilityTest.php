<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\DeviceObservation;
use App\Models\Router;
use App\Services\Monitoring\LiveMonitoringService;
use App\Services\Monitoring\RouterCapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveMonitoringCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_routeros_v6_and_v7_are_supported_for_read_only_monitoring_capabilities(): void
    {
        foreach (['6.49.13', '7.20.1'] as $version) {
            $router = Router::factory()->create();
            $snapshot = app(RouterCapabilityService::class)->refreshFromMonitoring($router, [
                'version' => $version,
                'architecture' => 'arm',
                'board' => 'test-board',
            ]);

            $this->assertSame('supported', $snapshot->capabilities['arp.read']);
            $this->assertSame('supported', $snapshot->capabilities['queue.simple.read']);
            $this->assertSame((int) explode('.', $version)[0], $snapshot->routeros_major);
        }
    }

    public function test_unknown_routeros_does_not_claim_capabilities(): void
    {
        $router = Router::factory()->create();
        $snapshot = app(RouterCapabilityService::class)->refreshFromMonitoring($router, ['version' => '8.0.0']);

        $this->assertSame('unknown', $snapshot->capabilities['arp.read']);
    }

    public function test_live_state_uses_fresh_device_presence_and_debounces_offline(): void
    {
        $router = Router::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $router->tenant_id]);
        $connection = CustomerConnection::create([
            'tenant_id' => $router->tenant_id,
            'customer_id' => $customer->id,
            'router_id' => $router->id,
            'status' => 'active',
            'metadata' => ['access_mode' => 'static_ip', 'network_mechanism' => 'simple_queue', 'network_identity' => '10.0.0.10/32'],
        ]);

        DeviceObservation::create([
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'customer_connection_id' => $connection->id,
            'source' => 'arp',
            'network_identity' => '10.0.0.10',
            'ip_address' => '10.0.0.10',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $service = app(LiveMonitoringService::class);
        $live = $service->refreshConnection($connection);
        $this->assertSame('online_idle', $live->state);
        $this->assertSame(0, $live->failure_streak);

        DeviceObservation::query()->delete();
        $this->assertSame('suspected_offline', $service->refreshConnection($connection)->state);
        $this->assertSame('suspected_offline', $service->refreshConnection($connection)->state);
        $this->assertSame('offline', $service->refreshConnection($connection)->state);
    }
}
