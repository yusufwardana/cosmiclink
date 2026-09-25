<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\InternetPackage;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\Monitoring\LiveConnectionStatus;
use App\Services\Network\GoNetworkMonitoringClient;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class LiveMonitoringSchedulerTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-25 01:00:00 UTC');
        config()->set('monitoring.driver', 'engine');
        config()->set('monitoring.traffic.enabled', true);
        config()->set('monitoring.traffic.enrichment_interval_seconds', 300);
        config()->set('monitoring.traffic.future_tolerance_seconds', 30);
        config()->set('monitoring.live.freshness_seconds', 90);
        config()->set('monitoring.live.offline_failure_threshold', 3);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_successful_collection_refreshes_live_state_and_capabilities_after_commit(): void
    {
        [$router, $connection] = $this->connection();
        $client = Mockery::mock(GoNetworkMonitoringClient::class);
        $client->shouldReceive('collectTraffic')->once()->andReturn($this->snapshot());
        $this->app->instance(GoNetworkMonitoringClient::class, $client);

        $this->artisan('monitoring:collect-traffic')
            ->expectsOutput('Traffic collections written: 1')
            ->assertSuccessful();

        $live = $connection->liveState()->firstOrFail();
        $this->assertSame(LiveConnectionStatus::SUSPECTED_OFFLINE, $live->state);
        $this->assertSame(1, $live->failure_streak);
        $this->assertSame(
            $live->id,
            Cache::get('live:connection:'.$connection->tenant_id.':'.$connection->id)['id'],
        );
        $snapshot = $router->capabilitySnapshots()->latest('verified_at')->firstOrFail();
        $this->assertSame('supported', $snapshot->capabilities['queue.simple.read']);
        $this->assertSame('unknown', $snapshot->capabilities['arp.read']);
    }

    public function test_failed_collection_marks_unknown_without_incrementing_absence_streak(): void
    {
        [, $connection] = $this->connection();
        $client = Mockery::mock(GoNetworkMonitoringClient::class);
        $client->shouldReceive('collectTraffic')->once()->andReturn([
            'reachable' => false,
            'failure' => ['code' => 'ROUTER_TIMEOUT'],
        ]);
        $this->app->instance(GoNetworkMonitoringClient::class, $client);

        $this->artisan('monitoring:collect-traffic')
            ->expectsOutput('Traffic collections written: 0')
            ->assertSuccessful();

        $live = $connection->liveState()->firstOrFail();
        $this->assertSame(LiveConnectionStatus::UNKNOWN, $live->state);
        $this->assertSame(0, $live->failure_streak);
        $this->assertSame('ROUTER_TIMEOUT', $live->metadata['failure_code']);
    }

    private function connection(): array
    {
        $tenant = Tenant::factory()->create();
        $router = Router::factory()->for($tenant)->create();
        $customer = Customer::factory()->for($tenant)->create();
        $package = InternetPackage::factory()->for($tenant)->create();
        $connection = CustomerConnection::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'internet_package_id' => $package->id,
            'router_id' => $router->id,
            'status' => 'active',
            'metadata' => [
                'access_mode' => 'static_ip',
                'network_mechanism' => 'simple_queue',
                'network_identity' => '10.0.0.10/32',
            ],
        ]);

        return [$router, $connection];
    }

    private function snapshot(): array
    {
        return ['reachable' => true, 'snapshot' => [
            'collected_at' => now()->toISOString(),
            'router' => ['version' => '6.49.13', 'architecture' => 'arm', 'board_name' => 'test-board'],
            'evidence' => [
                'datasets' => ['system_resource', 'interfaces', 'simple_queues', 'hotspot_sessions'],
                'enrichment_collected' => false,
            ],
            'interfaces' => [],
            'simple_queues' => [],
            'hotspot_sessions' => [],
        ]];
    }
}
