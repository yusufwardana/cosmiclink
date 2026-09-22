<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\InternetPackage;
use App\Models\NetworkAccount;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\TrafficBucket;
use App\Services\Monitoring\TrafficAnalyticsPeriod;
use App\Services\Monitoring\TrafficAnalyticsService;
use App\Services\Monitoring\TrafficConnectionMode;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase6KTrafficAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-22 20:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_overview_uses_half_open_bucket_windows_and_calculates_throughput_and_peak_hour(): void
    {
        $tenant = Tenant::factory()->create();
        $router = Router::factory()->for($tenant)->create();
        $other = Router::factory()->for($tenant)->create();
        $foreign = Router::factory()->for(Tenant::factory())->create();

        $this->bucket($router, 'simple_queue', 'static-a', '2026-09-22 10:00:00', 300, 600, true);
        $this->bucket($router, 'simple_queue', 'static-a', '2026-09-22 10:05:00', 600, 900, true);
        $this->bucket($router, 'hotspot_username', 'alice', '2026-09-22 11:00:00', 200, 400, true);
        $this->bucket($router, 'interface', 'wan', '2026-09-22 10:00:00', 1000, 2000, false, ['name' => 'ether1-wan', 'type' => 'ether']);
        $this->bucket($router, 'simple_queue', 'at-start', '2026-09-15 20:00:00', 10, 20, true);
        $this->bucket($router, 'simple_queue', 'before-start', '2026-09-15 19:55:00', 9999, 9999, true);
        $this->bucket($router, 'simple_queue', 'at-today-end', '2026-09-23 00:00:00', 9999, 9999, true);
        $this->bucket($other, 'simple_queue', 'other-router', '2026-09-22 12:00:00', 50, 50, true);
        $this->bucket($other, 'simple_queue', 'at-rolling-end', '2026-09-22 20:00:00', 9999, 9999, true);
        $this->bucket($foreign, 'simple_queue', 'foreign', '2026-09-22 12:00:00', 9999, 9999, true);

        $service = app(TrafficAnalyticsService::class);
        $today = $service->overview($tenant->id, $this->period('today'), ['router_id' => $router->id]);
        $seven = $service->overview($tenant->id, $this->period('7d'), ['router_id' => $router->id]);
        $thirty = $service->overview($tenant->id, $this->period('30d'), ['router_id' => $router->id]);
        $rollingBoundary = $service->overview($tenant->id, $this->period('7d'), ['router_id' => $other->id]);

        $this->assertSame(['upload_bytes' => 900, 'download_bytes' => 1500, 'total_bytes' => 2400], array_intersect_key($today['subscriber_modes']['STATIC_SIMPLE_QUEUE'], array_flip(['upload_bytes', 'download_bytes', 'total_bytes'])));
        $this->assertSame(32, $today['subscriber_modes']['STATIC_SIMPLE_QUEUE']['average_throughput_bps']);
        $this->assertSame(40, $today['subscriber_modes']['STATIC_SIMPLE_QUEUE']['peak_throughput_bps']);
        $this->assertSame('2026-09-22T10:00:00+00:00', $today['peak_hour']['subscriber_modes']['STATIC_SIMPLE_QUEUE']['hour']);
        $this->assertSame(2400, $today['peak_hour']['subscriber_modes']['STATIC_SIMPLE_QUEUE']['total_bytes']);
        $this->assertSame(600, $today['peak_hour']['subscriber_modes']['HOTSPOT']['total_bytes']);
        $this->assertSame(3000, $today['peak_hour']['interfaces']['total_bytes']);
        $this->assertSame(1000, $today['interfaces']['upload_bytes']);
        $this->assertSame(2000, $today['interfaces']['download_bytes']);
        $this->assertSame(2430, $seven['subscriber_modes']['STATIC_SIMPLE_QUEUE']['total_bytes']);
        $this->assertSame(22428, $thirty['subscriber_modes']['STATIC_SIMPLE_QUEUE']['total_bytes']);
        $this->assertSame(100, $rollingBoundary['subscriber_modes']['STATIC_SIMPLE_QUEUE']['total_bytes']);
    }

    public function test_rankings_support_metrics_limits_lowest_static_only_and_hotspot_anti_double_counting(): void
    {
        $tenant = Tenant::factory()->create();
        $router = Router::factory()->for($tenant)->create();
        foreach (range(1, 21) as $index) {
            $this->bucket($router, 'simple_queue', sprintf('static-%02d', $index), '2026-09-22 10:00:00', $index * 10, $index * 100, true);
        }
        $this->bucket($router, 'simple_queue', 'dynamic-alice', '2026-09-22 10:00:00', 9000, 9000, false, ['dynamic' => true]);
        $this->bucket($router, 'hotspot_username', 'alice', '2026-09-22 10:00:00', 30, 70, true);
        $this->bucket($router, 'hotspot_username', 'alice', '2026-09-22 10:05:00', 20, 80, true);

        $service = app(TrafficAnalyticsService::class);
        $top = $service->rankSubscribers($tenant->id, $this->period('today'), TrafficConnectionMode::STATIC_SIMPLE_QUEUE, 'total', 10);
        $uploads = $service->rankSubscribers($tenant->id, $this->period('today'), TrafficConnectionMode::STATIC_SIMPLE_QUEUE, 'upload', 20);
        $lowest = $service->rankSubscribers($tenant->id, $this->period('today'), TrafficConnectionMode::STATIC_SIMPLE_QUEUE, 'lowest', 10);
        $hotspot = $service->rankSubscribers($tenant->id, $this->period('today'), TrafficConnectionMode::HOTSPOT, 'download', 10);
        $pppoe = $service->rankSubscribers($tenant->id, $this->period('today'), TrafficConnectionMode::PPPOE, 'total', 10);

        $this->assertCount(10, $top);
        $this->assertSame('static-21', $top[0]['identity']);
        $this->assertCount(20, $uploads);
        $this->assertSame('static-01', $lowest[0]['identity']);
        $this->assertSame(['alice'], array_column($hotspot, 'identity'));
        $this->assertSame(50, $hotspot[0]['upload_bytes']);
        $this->assertSame(150, $hotspot[0]['download_bytes']);
        $this->assertSame(200, $hotspot[0]['total_bytes']);
        $this->assertSame(['supported' => false, 'mode' => 'PPPOE', 'reason' => 'AUTHORITATIVE_TRAFFIC_UNAVAILABLE'], $pppoe);
    }

    public function test_mapping_enriches_exact_tenant_router_identity_and_filters_without_hiding_unmapped_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $router = Router::factory()->for($tenant)->create();
        $customer = Customer::factory()->for($tenant)->create(['name' => 'Alice Customer']);
        $package = InternetPackage::factory()->for($tenant)->create(['name' => 'Gold']);
        $account = NetworkAccount::create(['tenant_id' => $tenant->id, 'router_id' => $router->id, 'username' => ' Alice ', 'profile' => 'default', 'status' => 'active']);
        $connection = CustomerConnection::factory()->for($tenant)->for($customer)->for($router)->create([
            'internet_package_id' => $package->id,
            'network_account_id' => $account->id,
        ]);
        $otherRouter = Router::factory()->for($tenant)->create();
        NetworkAccount::create(['tenant_id' => $tenant->id, 'router_id' => $otherRouter->id, 'username' => 'unmapped', 'profile' => 'default', 'status' => 'active']);

        $this->bucket($router, 'hotspot_username', 'alice', '2026-09-22 10:00:00', 100, 200, true);
        $this->bucket($router, 'hotspot_username', 'unmapped', '2026-09-22 10:00:00', 50, 50, true);

        $service = app(TrafficAnalyticsService::class);
        $all = $service->rankSubscribers($tenant->id, $this->period('today'), TrafficConnectionMode::HOTSPOT, 'total', 10);
        $filtered = $service->rankSubscribers($tenant->id, $this->period('today'), TrafficConnectionMode::HOTSPOT, 'total', 10, [
            'customer_id' => $customer->id,
            'connection_id' => $connection->id,
            'package_id' => $package->id,
        ]);
        $overview = $service->overview($tenant->id, $this->period('today'), ['customer_id' => $customer->id]);
        $history = $service->subscriberHistory($tenant->id, $this->period('today'), TrafficConnectionMode::HOTSPOT, 'unmapped', ['customer_id' => $customer->id]);
        $peakHours = $service->peakHours($tenant->id, $this->period('today'), ['customer_id' => $customer->id]);

        $this->assertCount(2, $all);
        $mapped = collect($all)->firstWhere('identity', 'alice');
        $unmapped = collect($all)->firstWhere('identity', 'unmapped');
        $this->assertTrue($mapped['mapped']);
        $this->assertSame(['id' => $customer->id, 'name' => 'Alice Customer'], $mapped['customer']);
        $this->assertSame($connection->id, $mapped['connection']['id']);
        $this->assertSame(['id' => $package->id, 'name' => 'Gold'], $mapped['package']);
        $this->assertFalse($unmapped['mapped']);
        $this->assertNull($unmapped['customer']);
        $this->assertSame(['alice'], array_column($filtered, 'identity'));
        $this->assertSame(300, $overview['subscriber_modes']['HOTSPOT']['total_bytes']);
        $this->assertSame([], $history);
        $this->assertSame(300, $peakHours['subscriber_modes']['HOTSPOT'][0]['total_bytes']);
    }

    public function test_histories_keep_subscribers_and_interfaces_separate_and_duplicate_buckets_are_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $router = Router::factory()->for($tenant)->create();
        $this->bucket($router, 'simple_queue', 'client-a', '2026-09-22 10:00:00', 10, 20, true);
        $this->bucket($router, 'interface', 'wan', '2026-09-22 10:00:00', 100, 200, false, ['name' => 'ether1-wan', 'type' => 'ether']);
        $this->bucket($router, 'interface', 'bridge', '2026-09-22 10:05:00', 50, 75, false, ['name' => 'bridge-clients', 'type' => 'bridge']);

        $service = app(TrafficAnalyticsService::class);
        $subscriber = $service->subscriberHistory($tenant->id, $this->period('today'), TrafficConnectionMode::STATIC_SIMPLE_QUEUE, 'client-a');
        $interfaces = $service->interfaceHistory($tenant->id, $this->period('today'));

        $this->assertSame(30, $subscriber[0]['total_bytes']);
        $this->assertSame(['bridge-clients', 'ether1-wan'], collect($interfaces)->pluck('name')->sort()->values()->all());
        $this->assertSame(300, collect($interfaces)->firstWhere('name', 'ether1-wan')['total_bytes']);

        $this->expectException(QueryException::class);
        $this->bucket($router, 'simple_queue', 'client-a', '2026-09-22 10:00:00', 999, 999, true);
    }

    public function test_peak_hours_keep_subscriber_modes_and_interfaces_separate(): void
    {
        $tenant = Tenant::factory()->create();
        $router = Router::factory()->for($tenant)->create();
        $this->bucket($router, 'simple_queue', 'static-a', '2026-09-22 10:00:00', 10, 20, true);
        $this->bucket($router, 'hotspot_username', 'alice', '2026-09-22 11:00:00', 100, 200, true);
        $this->bucket($router, 'interface', 'wan', '2026-09-22 12:00:00', 1000, 2000, false);

        $result = app(TrafficAnalyticsService::class)->peakHours($tenant->id, $this->period('today'));

        $this->assertSame(30, $result['subscriber_modes']['STATIC_SIMPLE_QUEUE'][0]['total_bytes']);
        $this->assertSame(300, $result['subscriber_modes']['HOTSPOT'][0]['total_bytes']);
        $this->assertSame(3000, $result['interfaces'][0]['total_bytes']);
        $this->assertArrayHasKey('PPPOE', $result['subscriber_modes']);
        $this->assertFalse($result['subscriber_modes']['PPPOE']['supported']);
    }

    public function test_overview_and_ranking_query_counts_do_not_grow_per_identity(): void
    {
        $tenant = Tenant::factory()->create();
        $router = Router::factory()->for($tenant)->create();
        foreach (range(1, 20) as $index) {
            $identity = "client-{$index}";
            $this->bucket($router, 'simple_queue', $identity, '2026-09-22 10:00:00', $index, $index, true);
            $customer = Customer::factory()->for($tenant)->create();
            $package = InternetPackage::factory()->for($tenant)->create();
            $account = NetworkAccount::create(['tenant_id' => $tenant->id, 'router_id' => $router->id, 'username' => $identity, 'profile' => 'default', 'status' => 'active']);
            CustomerConnection::factory()->for($tenant)->for($customer)->for($router)->create(['internet_package_id' => $package->id, 'network_account_id' => $account->id]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $service = app(TrafficAnalyticsService::class);
        $service->overview($tenant->id, $this->period('today'));
        $overviewQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $service->rankSubscribers($tenant->id, $this->period('today'), TrafficConnectionMode::STATIC_SIMPLE_QUEUE, 'total', 20);
        $rankingQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(3, $overviewQueries);
        $this->assertLessThanOrEqual(5, $rankingQueries);
    }

    private function period(string $key): TrafficAnalyticsPeriod
    {
        return TrafficAnalyticsPeriod::from($key, Carbon::now('UTC'));
    }

    private function bucket(Router $router, string $source, string $identity, string $at, int $upload, int $download, bool $authoritative, array $metadata = []): TrafficBucket
    {
        return TrafficBucket::create([
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'source_type' => $source,
            'subject_key' => $identity,
            'bucket_started_at' => Carbon::parse($at, 'UTC'),
            'upload_bytes' => $upload,
            'download_bytes' => $download,
            'sample_count' => 1,
            'subscriber_authoritative' => $authoritative,
            'metadata' => $metadata,
        ]);
    }
}
