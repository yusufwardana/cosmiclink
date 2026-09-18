<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\HealthObservation;
use App\Models\InternetPackage;
use App\Models\NetworkAccount;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Monitoring\HealthState;
use App\Services\Monitoring\MonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Phase6GMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_engine_failure_persists_unknown_without_opening_customer_outage(): void
    {
        config()->set('monitoring.driver', 'engine');
        config()->set('network.go.url', 'http://network-engine.test');
        config()->set('network.go.token', 'test-token');
        Http::fake(['network-engine.test/*' => Http::response(['reachable' => false, 'failure' => ['code' => 'ROUTER_TIMEOUT', 'message' => 'safe']])]);
        [$tenant, $user] = $this->tenantWithUser();
        $connection = $this->connection($tenant);

        app(MonitoringService::class)->observeTenant($tenant->id, $user);

        $this->assertSame(HealthState::UNKNOWN->value, HealthObservation::where('subject_type', 'connection')->where('subject_id', $connection->id)->value('health_state'));
        $this->assertDatabaseCount('outage_incidents', 0);
    }

    public function test_unchanged_state_is_checkpoint_deduplicated(): void
    {
        config()->set('monitoring.checkpoint_seconds', 300);
        [$tenant, $user] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create(['monitoring_state' => 'online']);

        app(MonitoringService::class)->observeRouter($router, $user);
        app(MonitoringService::class)->observeRouter($router->fresh(), $user);

        $this->assertSame(1, HealthObservation::where('subject_type', 'router')->where('subject_id', $router->id)->count());
    }

    public function test_monitoring_api_exposes_source_without_cross_tenant_data(): void
    {
        [$tenant, $user] = $this->tenantWithUser('Tenant A');
        [$otherTenant] = $this->tenantWithUser('Tenant B');
        $router = Router::factory()->for($tenant)->create();
        $otherRouter = Router::factory()->for($otherTenant)->create(['name' => 'SECRET OTHER ROUTER']);
        HealthObservation::factory()->for($tenant)->create(['subject_type' => 'router', 'subject_id' => $router->id, 'health_state' => 'online']);
        HealthObservation::factory()->for($otherTenant)->create(['subject_type' => 'router', 'subject_id' => $otherRouter->id, 'health_state' => 'online']);

        $response = $this->actingAs($user)->getJson('/api/v1/monitoring');

        $response->assertOk()->assertJsonPath('data.source', 'SIMULATION')->assertDontSee('SECRET OTHER ROUTER');
    }

    private function connection(Tenant $tenant): CustomerConnection
    {
        $router = Router::factory()->for($tenant)->create();
        $customer = Customer::factory()->for($tenant)->create();
        $package = InternetPackage::factory()->for($tenant)->create();
        $account = NetworkAccount::create(['tenant_id' => $tenant->id, 'router_id' => $router->id, 'username' => $customer->customer_code, 'profile' => $package->network_profile, 'status' => 'active']);

        return CustomerConnection::factory()->for($customer)->for($package)->for($router)->create(['tenant_id' => $tenant->id, 'network_account_id' => $account->id, 'status' => 'active', 'provisioned_at' => now()]);
    }

    private function tenantWithUser(string $name = 'DemoNet ISP'): array
    {
        $tenant = Tenant::factory()->create(['name' => $name]);

        return [$tenant, User::factory()->for($tenant)->create()];
    }
}
