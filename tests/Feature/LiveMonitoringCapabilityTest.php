<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\DeviceObservation;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\TrafficCollection;
use App\Models\TrafficSample;
use App\Services\Monitoring\LiveConnectionStatus;
use App\Services\Monitoring\LiveMonitoringCycle;
use App\Services\Monitoring\LiveMonitoringService;
use App\Services\Monitoring\RouterCapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LiveMonitoringCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-25 01:00:00 UTC');
        config()->set('monitoring.live.freshness_seconds', 90);
        config()->set('monitoring.live.offline_failure_threshold', 3);
        config()->set('monitoring.capabilities.checkpoint_seconds', 3600);
        config()->set('monitoring.capabilities.retention_days', 90);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_health_capabilities_are_based_on_observed_datasets_not_routeros_major(): void
    {
        foreach (['6.49.13', '7.20.1', '8.0.0'] as $version) {
            $router = Router::factory()->create();
            $snapshot = app(RouterCapabilityService::class)->recordHealthEvidence($router, [
                'version' => $version,
                'architecture' => 'arm',
                'board' => 'test-board',
                'ppp_active' => [],
            ], now());

            $this->assertSame('supported', $snapshot->capabilities['system.read']);
            $this->assertSame('supported', $snapshot->capabilities['pppoe.sessions.read']);
            $this->assertSame('unknown', $snapshot->capabilities['arp.read']);
            $this->assertSame('unknown', $snapshot->capabilities['queue.simple.read']);
        }
    }

    public function test_health_payload_does_not_claim_a_missing_pppoe_dataset(): void
    {
        $router = Router::factory()->create();

        $snapshot = app(RouterCapabilityService::class)->recordHealthEvidence($router, [
            'version' => '6.49.13',
            'architecture' => 'arm',
            'board' => 'test-board',
        ], now());

        $this->assertSame('supported', $snapshot->capabilities['system.read']);
        $this->assertSame('unknown', $snapshot->capabilities['pppoe.sessions.read']);
    }

    public function test_capability_snapshots_merge_evidence_deduplicate_and_prune(): void
    {
        $router = Router::factory()->create();
        $service = app(RouterCapabilityService::class);

        $health = $service->recordHealthEvidence($router, [
            'version' => '6.49.13', 'architecture' => 'arm', 'board' => 'test-board', 'ppp_active' => [],
        ], now());
        $duplicate = $service->recordHealthEvidence($router, [
            'version' => '6.49.13', 'architecture' => 'arm', 'board' => 'test-board', 'ppp_active' => [],
        ], now()->addMinute());
        $this->assertTrue($health->is($duplicate));

        $traffic = $service->recordTrafficEvidence($router, [
            'collected_at' => now()->addMinutes(2),
            'router' => ['version' => '6.49.13', 'architecture' => 'arm', 'board_name' => 'test-board'],
            'evidence' => ['datasets' => ['system_resource', 'interfaces', 'simple_queues', 'hotspot_sessions', 'arp_entries', 'dhcp_leases']],
        ]);
        $this->assertSame('supported', $traffic->capabilities['traffic.interface.read']);
        $this->assertSame('supported', $traffic->capabilities['queue.simple.read']);
        $this->assertSame('supported', $traffic->capabilities['hotspot.sessions.read']);
        $this->assertSame('supported', $traffic->capabilities['arp.read']);
        $this->assertSame('supported', $traffic->capabilities['dhcp.leases.read']);
        $this->assertSame(2, $router->capabilitySnapshots()->count());

        $health->forceFill(['verified_at' => now()->subDays(91)])->save();
        $this->assertSame(1, $service->prune());
        $this->assertDatabaseHas('router_capability_snapshots', ['id' => $traffic->id]);
    }

    public function test_fresh_presence_is_online_and_uses_evidence_timestamp(): void
    {
        [$router, $connection] = $this->staticConnection();
        $seenAt = now()->subSeconds(10);

        DeviceObservation::create([
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'customer_connection_id' => $connection->id,
            'source' => 'arp',
            'network_identity' => '10.0.0.10',
            'ip_address' => '10.0.0.10',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'first_seen_at' => $seenAt,
            'last_seen_at' => $seenAt,
        ]);

        $live = app(LiveMonitoringService::class)->refreshConnection(
            $connection,
            LiveMonitoringCycle::successful(now(), 'device'),
        );

        $this->assertSame(LiveConnectionStatus::ONLINE, $live->state);
        $this->assertSame(0, $live->failure_streak);
        $this->assertTrue($live->last_seen_at->equalTo($seenAt));
        $this->assertTrue($live->observed_at->equalTo(now()));
    }

    public function test_successful_absence_debounces_caps_and_recovers(): void
    {
        [$router, $connection] = $this->staticConnection();
        $service = app(LiveMonitoringService::class);
        $cycle = LiveMonitoringCycle::successful(now(), 'traffic');

        $this->assertSame(LiveConnectionStatus::SUSPECTED_OFFLINE, $service->refreshConnection($connection, $cycle)->state);
        $this->assertSame(LiveConnectionStatus::SUSPECTED_OFFLINE, $service->refreshConnection($connection, $cycle)->state);
        $this->assertSame(LiveConnectionStatus::OFFLINE, $service->refreshConnection($connection, $cycle)->state);
        $offline = $service->refreshConnection($connection, $cycle);
        $this->assertSame(3, $offline->failure_streak);

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
        $recovered = $service->refreshConnection($connection, $cycle);
        $this->assertSame(LiveConnectionStatus::ONLINE, $recovered->state);
        $this->assertSame(0, $recovered->failure_streak);
    }

    public function test_failed_and_stale_cycles_do_not_increment_absence_streak(): void
    {
        [, $connection] = $this->staticConnection();
        $service = app(LiveMonitoringService::class);
        $suspected = $service->refreshConnection($connection, LiveMonitoringCycle::successful(now(), 'traffic'));
        $this->assertSame(1, $suspected->failure_streak);

        $unknown = $service->refreshConnection($connection, LiveMonitoringCycle::failed(now(), 'ROUTER_TIMEOUT', 'traffic'));
        $this->assertSame(LiveConnectionStatus::UNKNOWN, $unknown->state);
        $this->assertSame(1, $unknown->failure_streak);
        $this->assertArrayNotHasKey('activity_state', $unknown->metadata);

        $stale = $service->refreshConnection($connection, LiveMonitoringCycle::successful(now()->subMinutes(5), 'traffic'));
        $this->assertSame(LiveConnectionStatus::STALE, $stale->state);
        $this->assertSame(1, $stale->failure_streak);
    }

    public function test_valid_traffic_uses_actual_collection_interval_for_bps(): void
    {
        [$router, $connection] = $this->staticConnection();
        $previous = TrafficCollection::create([
            'tenant_id' => $router->tenant_id, 'router_id' => $router->id, 'collected_at' => now()->subSeconds(45),
            'provider' => 'routeros', 'datasets' => [], 'enrichment_collected' => false,
        ]);
        $current = TrafficCollection::create([
            'tenant_id' => $router->tenant_id, 'router_id' => $router->id, 'collected_at' => now(),
            'provider' => 'routeros', 'datasets' => [], 'enrichment_collected' => false,
        ]);
        TrafficSample::create([
            'traffic_collection_id' => $previous->id, 'tenant_id' => $router->tenant_id, 'router_id' => $router->id,
            'source_type' => 'simple_queue', 'source_key' => '*Q', 'subject_key' => '10.0.0.10/32',
            'upload_bytes' => 100, 'download_bytes' => 200, 'delta_status' => 'first_observation', 'observed_at' => $previous->collected_at,
        ]);
        TrafficSample::create([
            'traffic_collection_id' => $current->id, 'tenant_id' => $router->tenant_id, 'router_id' => $router->id,
            'source_type' => 'simple_queue', 'source_key' => '*Q', 'subject_key' => '10.0.0.10/32',
            'upload_bytes' => 550, 'download_bytes' => 1100, 'upload_delta_bytes' => 450, 'download_delta_bytes' => 900,
            'delta_status' => 'valid', 'observed_at' => $current->collected_at,
        ]);

        $live = app(LiveMonitoringService::class)->refreshConnection($connection, LiveMonitoringCycle::successful(now(), 'traffic'));
        $this->assertSame(LiveConnectionStatus::ONLINE, $live->state);
        $this->assertSame(80, $live->upload_bps);
        $this->assertSame(160, $live->download_bps);
        $this->assertSame('active', $live->metadata['activity_state']);
    }

    public function test_refresh_router_and_evidence_are_tenant_and_router_scoped(): void
    {
        [$routerA, $connectionA] = $this->staticConnection('10.0.0.10/32');
        [$routerB, $connectionB] = $this->staticConnection('10.0.0.10/32');
        DeviceObservation::create([
            'tenant_id' => $routerB->tenant_id, 'router_id' => $routerB->id, 'customer_connection_id' => $connectionB->id,
            'source' => 'arp', 'network_identity' => '10.0.0.10', 'ip_address' => '10.0.0.10',
            'mac_address' => 'AA:BB:CC:DD:EE:FF', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $count = app(LiveMonitoringService::class)->refreshRouter($routerA, LiveMonitoringCycle::successful(now(), 'traffic'));

        $this->assertSame(1, $count);
        $this->assertSame(LiveConnectionStatus::SUSPECTED_OFFLINE, $connectionA->liveState()->firstOrFail()->state);
        $this->assertDatabaseMissing('live_connection_states', ['customer_connection_id' => $connectionB->id]);
    }

    private function staticConnection(string $identity = '10.0.0.10/32'): array
    {
        $tenant = Tenant::factory()->create();
        $router = Router::factory()->for($tenant)->create();
        $customer = Customer::factory()->for($tenant)->create();
        $connection = CustomerConnection::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'router_id' => $router->id,
            'status' => 'active',
            'metadata' => ['access_mode' => 'static_ip', 'network_mechanism' => 'simple_queue', 'network_identity' => $identity],
        ]);

        return [$router, $connection];
    }
}
