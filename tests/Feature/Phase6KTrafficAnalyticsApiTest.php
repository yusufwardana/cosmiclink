<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Tenant;
use App\Models\TrafficBucket;
use App\Models\User;
use App\Services\Monitoring\TrafficCollectionService;
use App\Services\Network\GoNetworkMonitoringClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class Phase6KTrafficAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-22 20:00:00 UTC');
        $this->app->instance(GoNetworkMonitoringClient::class, Mockery::mock(GoNetworkMonitoringClient::class, fn ($mock) => $mock->shouldNotReceive('collectTraffic')));
        $this->app->instance(TrafficCollectionService::class, Mockery::mock(TrafficCollectionService::class, fn ($mock) => $mock->shouldNotReceive('collectRouter')->shouldNotReceive('runScheduled')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_traffic_endpoints_require_authentication(): void
    {
        foreach (['overview', 'rankings', 'subscriber-history', 'interface-history', 'peak-hours'] as $endpoint) {
            $this->getJson("/api/v1/traffic/{$endpoint}")->assertUnauthorized();
        }
    }

    public function test_all_analytics_endpoints_return_tenant_scoped_postgresql_data(): void
    {
        [$tenant, $user, $router] = $this->tenantUserRouter();
        $foreign = Router::factory()->for(Tenant::factory())->create();
        $this->bucket($router, 'simple_queue', 'client-a', 10, 20, true);
        $this->bucket($router, 'interface', 'wan', 100, 200, false, ['name' => 'ether1-wan', 'type' => 'ether']);
        $this->bucket($foreign, 'simple_queue', 'foreign', 9999, 9999, true);

        $this->actingAs($user)->getJson('/api/v1/traffic/overview?period=today')
            ->assertOk()
            ->assertJsonPath('data.subscriber_modes.STATIC_SIMPLE_QUEUE.total_bytes', 30)
            ->assertJsonPath('data.interfaces.total_bytes', 300);

        $this->actingAs($user)->getJson('/api/v1/traffic/rankings?period=today&mode=STATIC_SIMPLE_QUEUE&metric=total&top=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.identity', 'client-a');

        $this->actingAs($user)->getJson('/api/v1/traffic/subscriber-history?period=today&mode=STATIC_SIMPLE_QUEUE&identity=client-a')
            ->assertOk()
            ->assertJsonPath('data.0.total_bytes', 30);

        $this->actingAs($user)->getJson('/api/v1/traffic/interface-history?period=today')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'ether1-wan')
            ->assertJsonPath('data.0.total_bytes', 300);

        $this->actingAs($user)->getJson('/api/v1/traffic/peak-hours?period=today')
            ->assertOk()
            ->assertJsonPath('data.subscriber_modes.STATIC_SIMPLE_QUEUE.0.total_bytes', 30)
            ->assertJsonPath('data.interfaces.0.total_bytes', 300);
    }

    public function test_validation_is_exact_and_foreign_tenant_filters_are_rejected(): void
    {
        [, $user] = $this->tenantUserRouter();
        $foreign = Router::factory()->for(Tenant::factory())->create();

        $this->actingAs($user)->getJson('/api/v1/traffic/rankings?period=90d&mode=PPPOE&metric=rate&top=50&router_id='.$foreign->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period', 'metric', 'top', 'router_id']);

        $this->actingAs($user)->getJson('/api/v1/traffic/subscriber-history?period=today&mode=HOTSPOT')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['identity']);
    }

    public function test_router_filter_and_pppoe_unsupported_response_are_explicit(): void
    {
        [$tenant, $user, $router] = $this->tenantUserRouter();
        $other = Router::factory()->for($tenant)->create();
        $this->bucket($router, 'simple_queue', 'router-a', 10, 20, true);
        $this->bucket($other, 'simple_queue', 'router-b', 100, 200, true);

        $this->actingAs($user)->getJson('/api/v1/traffic/rankings?period=today&mode=STATIC_SIMPLE_QUEUE&metric=download&top=10&router_id='.$router->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.identity', 'router-a');

        $this->actingAs($user)->getJson('/api/v1/traffic/rankings?period=today&mode=PPPOE&metric=total&top=10')
            ->assertOk()
            ->assertJsonPath('data.supported', false)
            ->assertJsonPath('data.reason', 'AUTHORITATIVE_TRAFFIC_UNAVAILABLE');
    }

    private function tenantUserRouter(): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create();
        $router = Router::factory()->for($tenant)->create();

        return [$tenant, $user, $router];
    }

    private function bucket(Router $router, string $source, string $identity, int $upload, int $download, bool $authoritative, array $metadata = []): void
    {
        TrafficBucket::create([
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'source_type' => $source,
            'subject_key' => $identity,
            'bucket_started_at' => Carbon::parse('2026-09-22 10:00:00', 'UTC'),
            'upload_bytes' => $upload,
            'download_bytes' => $download,
            'sample_count' => 1,
            'subscriber_authoritative' => $authoritative,
            'metadata' => $metadata,
        ]);
    }
}
