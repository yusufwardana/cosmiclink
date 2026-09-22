<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Tenant;
use App\Models\TrafficCollection;
use App\Services\Network\GoNetworkMonitoringClient;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class Phase6KTrafficSchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-22 13:00:00 UTC');
        config()->set('monitoring.driver', 'engine');
        config()->set('monitoring.traffic.enabled', true);
        config()->set('monitoring.traffic.enrichment_interval_seconds', 300);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_collection_command_collects_once_per_router_and_requests_enrichment_only_when_due(): void
    {
        $tenant = Tenant::factory()->create();
        $due = Router::factory()->for($tenant)->create();
        $recent = Router::factory()->for($tenant)->create();
        TrafficCollection::create(['tenant_id' => $tenant->id, 'router_id' => $recent->id, 'collected_at' => now()->subMinute(), 'provider' => 'routeros', 'datasets' => [], 'enrichment_collected' => true]);

        $client = Mockery::mock(GoNetworkMonitoringClient::class);
        $client->shouldReceive('collectTraffic')->once()->with(Mockery::on(fn (Router $router) => $router->is($due)), true)->andReturn($this->snapshot('2026-09-22T13:00:00Z'));
        $client->shouldReceive('collectTraffic')->once()->with(Mockery::on(fn (Router $router) => $router->is($recent)), false)->andReturn($this->snapshot('2026-09-22T13:00:01Z'));
        $this->app->instance(GoNetworkMonitoringClient::class, $client);

        $this->artisan('monitoring:collect-traffic')->expectsOutput('Traffic collections written: 2')->assertSuccessful();
        $this->assertSame(3, TrafficCollection::count());
    }

    public function test_collection_command_fails_closed_for_fake_or_disabled_configuration(): void
    {
        Router::factory()->for(Tenant::factory())->create();
        $client = Mockery::mock(GoNetworkMonitoringClient::class);
        $client->shouldNotReceive('collectTraffic');
        $this->app->instance(GoNetworkMonitoringClient::class, $client);

        config()->set('monitoring.driver', 'fake');
        $this->artisan('monitoring:collect-traffic')->expectsOutput('Traffic collections written: 0')->assertSuccessful();
        config()->set('monitoring.driver', 'engine');
        config()->set('monitoring.traffic.enabled', false);
        $this->artisan('monitoring:collect-traffic')->expectsOutput('Traffic collections written: 0')->assertSuccessful();
    }

    public function test_prune_command_and_scheduler_are_registered_without_overlap(): void
    {
        config()->set('monitoring.traffic.raw_retention_days', 14);
        config()->set('monitoring.traffic.bucket_retention_days', 90);

        $this->artisan('monitoring:prune-traffic')->expectsOutput('Pruned traffic collections: 0; buckets: 0')->assertSuccessful();

        $events = collect(app(Schedule::class)->events());
        $collect = $events->first(fn ($event) => str_contains($event->command ?? '', 'monitoring:collect-traffic'));
        $prune = $events->first(fn ($event) => str_contains($event->command ?? '', 'monitoring:prune-traffic'));
        $this->assertNotNull($collect);
        $this->assertSame('* * * * *', $collect->expression);
        $this->assertTrue($collect->withoutOverlapping);
        $this->assertNotNull($prune);
        $this->assertSame('0 0 * * *', $prune->expression);
        $this->assertTrue($prune->withoutOverlapping);
    }

    private function snapshot(string $collectedAt): array
    {
        return ['reachable' => true, 'snapshot' => [
            'collected_at' => $collectedAt,
            'router' => [],
            'evidence' => ['datasets' => ['system_resource', 'interfaces', 'simple_queues', 'hotspot_sessions'], 'enrichment_collected' => false],
            'interfaces' => [], 'simple_queues' => [], 'hotspot_sessions' => [],
        ]];
    }
}
