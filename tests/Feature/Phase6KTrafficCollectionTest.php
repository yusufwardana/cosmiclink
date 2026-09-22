<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Tenant;
use App\Models\TrafficBucket;
use App\Models\TrafficCollection;
use App\Models\TrafficSample;
use App\Services\Monitoring\TrafficCollectionService;
use App\Services\Network\GoNetworkMonitoringClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class Phase6KTrafficCollectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-22 13:00:00 UTC');
        config()->set('monitoring.driver', 'engine');
        config()->set('monitoring.traffic.enabled', true);
        config()->set('monitoring.traffic.enrichment_interval_seconds', 300);
        config()->set('monitoring.traffic.future_tolerance_seconds', 30);
        config()->set('monitoring.traffic.raw_retention_days', 14);
        config()->set('monitoring.traffic.bucket_retention_days', 90);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_first_and_increasing_observations_create_exact_separate_buckets(): void
    {
        $router = Router::factory()->for(Tenant::factory())->create();
        $this->bindSnapshots([
            $this->snapshot('2026-09-22T12:00:00Z', interfaces: [['source_key' => '*1', 'upload_bytes' => 100, 'download_bytes' => 200]], queues: [['source_key' => '*Q', 'subject_key' => '10.0.0.2/32', 'upload_bytes' => 50, 'download_bytes' => 70]], hotspots: [
                ['source_key' => 'session-a', 'subject_key' => 'alice', 'upload_bytes' => 10, 'download_bytes' => 20],
                ['source_key' => 'session-b', 'subject_key' => 'alice', 'upload_bytes' => 30, 'download_bytes' => 40],
            ]),
            $this->snapshot('2026-09-22T12:01:00Z', interfaces: [['source_key' => '*1', 'upload_bytes' => 130, 'download_bytes' => 250]], queues: [['source_key' => '*Q', 'subject_key' => '10.0.0.2/32', 'upload_bytes' => 60, 'download_bytes' => 90]], hotspots: [
                ['source_key' => 'session-a', 'subject_key' => 'alice', 'upload_bytes' => 15, 'download_bytes' => 29],
                ['source_key' => 'session-b', 'subject_key' => 'alice', 'upload_bytes' => 37, 'download_bytes' => 51],
            ]),
        ]);

        $service = app(TrafficCollectionService::class);
        $service->collectRouter($router);
        $service->collectRouter($router->fresh());

        $this->assertSame(4, TrafficSample::where('delta_status', 'first_observation')->count());
        $this->assertSame(4, TrafficSample::where('delta_status', 'valid')->count());
        $interface = TrafficBucket::where('source_type', 'interface')->sole();
        $queue = TrafficBucket::where('source_type', 'simple_queue')->sole();
        $sessions = TrafficBucket::where('source_type', 'hotspot_session')->orderBy('subject_key')->get();
        $username = TrafficBucket::where('source_type', 'hotspot_username')->sole();
        $this->assertSame([30, 50, 1], [$interface->upload_bytes, $interface->download_bytes, $interface->sample_count]);
        $this->assertSame([10, 20, 1], [$queue->upload_bytes, $queue->download_bytes, $queue->sample_count]);
        $this->assertSame([[5, 9], [7, 11]], $sessions->map(fn ($bucket) => [$bucket->upload_bytes, $bucket->download_bytes])->all());
        $this->assertSame('alice', $username->subject_key);
        $this->assertSame([12, 20, 2], [$username->upload_bytes, $username->download_bytes, $username->sample_count]);
    }

    public function test_reset_missing_reappearing_and_unavailable_counters_never_increment_buckets(): void
    {
        $router = Router::factory()->for(Tenant::factory())->create();
        $this->bindSnapshots([
            $this->snapshot('2026-09-22T12:00:00Z', interfaces: [['source_key' => '*1', 'upload_bytes' => 100, 'download_bytes' => 200], ['source_key' => '*2', 'upload_bytes' => 10, 'download_bytes' => 20]]),
            $this->snapshot('2026-09-22T12:01:00Z', interfaces: [['source_key' => '*1', 'upload_bytes' => 90, 'download_bytes' => 210]]),
            $this->snapshot('2026-09-22T12:02:00Z', interfaces: [['source_key' => '*1', 'upload_bytes' => null, 'download_bytes' => 220], ['source_key' => '*2', 'upload_bytes' => 20, 'download_bytes' => 40]]),
        ]);

        $service = app(TrafficCollectionService::class);
        $service->collectRouter($router);
        $service->collectRouter($router->fresh());
        $service->collectRouter($router->fresh());

        $statuses = TrafficSample::orderBy('observed_at')->orderBy('source_key')->pluck('delta_status')->all();
        $this->assertSame(['first_observation', 'first_observation', 'counter_reset', 'counter_unavailable', 'source_reappeared'], $statuses);
        $this->assertDatabaseCount('traffic_buckets', 0);
    }

    public function test_duplicate_is_idempotent_delayed_is_recorded_without_bucket_and_restart_uses_database_history(): void
    {
        $tenant = Tenant::factory()->create();
        $router = Router::factory()->for($tenant)->create();
        $other = Router::factory()->for($tenant)->create();
        $this->bindSnapshots([
            $this->snapshot('2026-09-22T12:00:00Z', interfaces: [['source_key' => '*1', 'upload_bytes' => 100, 'download_bytes' => 200]]),
            $this->snapshot('2026-09-22T12:00:00Z', interfaces: [['source_key' => '*1', 'upload_bytes' => 999, 'download_bytes' => 999]]),
            $this->snapshot('2026-09-22T11:59:00Z', interfaces: [['source_key' => '*1', 'upload_bytes' => 90, 'download_bytes' => 190]]),
            $this->snapshot('2026-09-22T12:01:00Z', interfaces: [['source_key' => '*1', 'upload_bytes' => 125, 'download_bytes' => 240]]),
            $this->snapshot('2026-09-22T12:01:00Z', interfaces: [['source_key' => '*1', 'upload_bytes' => 500, 'download_bytes' => 600]]),
        ]);

        app(TrafficCollectionService::class)->collectRouter($router);
        $this->assertNull(app(TrafficCollectionService::class)->collectRouter($router->fresh()));
        app(TrafficCollectionService::class)->collectRouter($router->fresh());
        app()->forgetInstance(TrafficCollectionService::class);
        app(TrafficCollectionService::class)->collectRouter($router->fresh());
        app(TrafficCollectionService::class)->collectRouter($other);

        $this->assertSame(4, TrafficCollection::count());
        $this->assertSame('delayed_observation', TrafficSample::where('router_id', $router->id)->where('observed_at', '2026-09-22 11:59:00')->value('delta_status'));
        $valid = TrafficSample::where('router_id', $router->id)->where('delta_status', 'valid')->sole();
        $this->assertSame([25, 40], [$valid->upload_delta_bytes, $valid->download_delta_bytes]);
        $bucket = TrafficBucket::where('router_id', $router->id)->where('source_type', 'interface')->sole();
        $this->assertSame([25, 40, 1], [$bucket->upload_bytes, $bucket->download_bytes, $bucket->sample_count]);
        $this->assertSame('first_observation', TrafficSample::where('router_id', $other->id)->value('delta_status'));
    }

    public function test_unreachable_or_future_snapshot_writes_nothing_and_prune_uses_separate_retention(): void
    {
        Carbon::setTestNow('2026-09-22 12:00:00');
        $router = Router::factory()->for(Tenant::factory())->create();
        $this->bindSnapshots([
            ['reachable' => false, 'failure' => ['code' => 'ROUTER_TIMEOUT']],
            $this->snapshot('2026-09-22T12:05:00Z'),
        ]);
        $service = app(TrafficCollectionService::class);
        $this->assertNull($service->collectRouter($router));
        $this->assertNull($service->collectRouter($router));
        $this->assertDatabaseCount('traffic_collections', 0);

        $oldCollection = TrafficCollection::create(['tenant_id' => $router->tenant_id, 'router_id' => $router->id, 'collected_at' => now()->subDays(15), 'provider' => 'routeros', 'datasets' => [], 'enrichment_collected' => false]);
        TrafficSample::create(['traffic_collection_id' => $oldCollection->id, 'tenant_id' => $router->tenant_id, 'router_id' => $router->id, 'source_type' => 'interface', 'source_key' => '*1', 'delta_status' => 'first_observation', 'observed_at' => now()->subDays(15)]);
        TrafficBucket::create(['tenant_id' => $router->tenant_id, 'router_id' => $router->id, 'source_type' => 'interface', 'subject_key' => '*1', 'bucket_started_at' => now()->subDays(91)]);

        $this->assertSame(['collections' => 1, 'buckets' => 1], $service->prune());
        $this->assertDatabaseCount('traffic_samples', 0);
    }

    public function test_counter_above_postgresql_bigint_range_is_unavailable_instead_of_saturating(): void
    {
        $router = Router::factory()->for(Tenant::factory())->create();
        $this->bindSnapshots([
            $this->snapshot('2026-09-22T12:00:00Z', interfaces: [[
                'source_key' => '*1',
                'upload_bytes' => '9223372036854775808',
                'download_bytes' => '20',
            ]]),
        ]);

        app(TrafficCollectionService::class)->collectRouter($router);

        $sample = TrafficSample::sole();
        $this->assertNull($sample->upload_bytes);
        $this->assertSame(20, $sample->download_bytes);
        $this->assertSame('counter_unavailable', $sample->delta_status);
        $this->assertDatabaseCount('traffic_buckets', 0);
    }

    public function test_bucket_context_marks_only_authoritative_subscriber_sources(): void
    {
        $router = Router::factory()->for(Tenant::factory())->create();
        $this->bindSnapshots([
            $this->snapshot('2026-09-22T12:00:00Z',
                interfaces: [['source_key' => '*1', 'name' => 'ether1-wan', 'type' => 'ether', 'upload_bytes' => 100, 'download_bytes' => 200]],
                queues: [
                    ['source_key' => '*Q1', 'subject_key' => '10.0.0.2/32', 'name' => 'static-client', 'target' => '10.0.0.2/32', 'dynamic' => false, 'upload_bytes' => 50, 'download_bytes' => 70],
                    ['source_key' => '*Q2', 'subject_key' => 'alice', 'name' => '<hotspot-alice>', 'target' => 'alice', 'dynamic' => true, 'upload_bytes' => 30, 'download_bytes' => 40],
                ],
                hotspots: [['source_key' => 'session-a', 'subject_key' => 'alice', 'upload_bytes' => 10, 'download_bytes' => 20]]),
            $this->snapshot('2026-09-22T12:01:00Z',
                interfaces: [['source_key' => '*1', 'name' => 'ether1-wan', 'type' => 'ether', 'upload_bytes' => 130, 'download_bytes' => 250]],
                queues: [
                    ['source_key' => '*Q1', 'subject_key' => '10.0.0.2/32', 'name' => 'static-client', 'target' => '10.0.0.2/32', 'dynamic' => false, 'upload_bytes' => 60, 'download_bytes' => 90],
                    ['source_key' => '*Q2', 'subject_key' => 'alice', 'name' => '<hotspot-alice>', 'target' => 'alice', 'dynamic' => true, 'upload_bytes' => 35, 'download_bytes' => 50],
                ],
                hotspots: [['source_key' => 'session-a', 'subject_key' => 'alice', 'upload_bytes' => 15, 'download_bytes' => 29]]),
        ]);

        $service = app(TrafficCollectionService::class);
        $service->collectRouter($router);
        $service->collectRouter($router->fresh());

        $interface = TrafficBucket::where('source_type', 'interface')->sole();
        $static = TrafficBucket::where('source_type', 'simple_queue')->where('subject_key', '10.0.0.2/32')->sole();
        $dynamic = TrafficBucket::where('source_type', 'simple_queue')->where('subject_key', 'alice')->sole();
        $hotspot = TrafficBucket::where('source_type', 'hotspot_username')->sole();

        $this->assertSame(['name' => 'ether1-wan', 'type' => 'ether'], $interface->metadata);
        $this->assertTrue($static->subscriber_authoritative);
        $this->assertSame('10.0.0.2/32', $static->metadata['target']);
        $this->assertFalse($dynamic->subscriber_authoritative);
        $this->assertTrue($dynamic->metadata['dynamic']);
        $this->assertTrue($hotspot->subscriber_authoritative);
        $this->assertSame(['username' => 'alice'], $hotspot->metadata);
    }

    private function bindSnapshots(array $snapshots): void
    {
        $client = Mockery::mock(GoNetworkMonitoringClient::class);
        foreach ($snapshots as $snapshot) {
            $client->shouldReceive('collectTraffic')->once()->andReturn($snapshot);
        }
        $this->app->instance(GoNetworkMonitoringClient::class, $client);
    }

    private function snapshot(string $collectedAt, array $interfaces = [], array $queues = [], array $hotspots = []): array
    {
        return ['reachable' => true, 'snapshot' => [
            'collected_at' => $collectedAt,
            'router' => ['version' => '6.49.13', 'cpu_load_percent' => 10],
            'evidence' => ['datasets' => ['system_resource', 'interfaces', 'simple_queues', 'hotspot_sessions'], 'enrichment_collected' => false],
            'interfaces' => $interfaces,
            'simple_queues' => $queues,
            'hotspot_sessions' => $hotspots,
            'dhcp_leases' => [['address' => '10.0.0.2', 'secret' => 'must-not-persist']],
            'arp_entries' => [],
        ]];
    }
}
