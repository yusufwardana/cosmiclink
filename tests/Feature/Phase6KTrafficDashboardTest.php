<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\InternetPackage;
use App\Models\NetworkAccount;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Monitoring\TrafficCollectionService;
use App\Services\Network\GoNetworkMonitoringClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class Phase6KTrafficDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(GoNetworkMonitoringClient::class, Mockery::mock(GoNetworkMonitoringClient::class, fn ($mock) => $mock->shouldNotReceive('collectTraffic')));
        $this->app->instance(TrafficCollectionService::class, Mockery::mock(TrafficCollectionService::class, fn ($mock) => $mock->shouldNotReceive('collectRouter')->shouldNotReceive('runScheduled')));
    }

    public function test_traffic_dashboard_requires_authentication(): void
    {
        $this->get('/traffic')->assertRedirect('/login');
    }

    public function test_dashboard_bootstrap_is_tenant_scoped_and_postgresql_only(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create();
        $router = Router::factory()->for($tenant)->create(['name' => 'Core Router']);
        $customer = Customer::factory()->for($tenant)->create(['name' => 'Mapped Subscriber']);
        $package = InternetPackage::factory()->for($tenant)->create(['name' => 'Home 50']);
        $account = NetworkAccount::create(['tenant_id' => $tenant->id, 'router_id' => $router->id, 'username' => 'subscriber-a', 'profile' => 'home', 'status' => 'active']);
        CustomerConnection::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'internet_package_id' => $package->id,
            'router_id' => $router->id,
            'network_account_id' => $account->id,
        ]);

        $foreign = Tenant::factory()->create();
        Router::factory()->for($foreign)->create(['name' => 'Foreign Router']);
        Customer::factory()->for($foreign)->create(['name' => 'Foreign Customer']);
        InternetPackage::factory()->for($foreign)->create(['name' => 'Foreign Package']);

        $response = $this->actingAs($user)->get('/traffic');

        $response->assertOk()
            ->assertSee('Traffic Intelligence')
            ->assertSee('id="traffic-intelligence-vue"', false)
            ->assertSee('Core Router')
            ->assertSee('Mapped Subscriber')
            ->assertSee('Home 50')
            ->assertSee('subscriber-a')
            ->assertSee('AUTHORITATIVE_TRAFFIC_UNAVAILABLE')
            ->assertSee('no historical data yet')
            ->assertDontSee('Foreign Router')
            ->assertDontSee('Foreign Customer')
            ->assertDontSee('Foreign Package');

        $shell = file_get_contents(resource_path('js/components/AppShell.vue'));
        $this->assertStringContainsString("label: 'Traffic Intelligence'", $shell);
        $this->assertStringContainsString("route: 'traffic.index'", $shell);

        $page = file_get_contents(resource_path('js/modules/traffic/TrafficIntelligencePage.vue'));
        $this->assertStringContainsString('Today', $page);
        $this->assertStringContainsString('7 Days', $page);
        $this->assertStringContainsString('30 Days', $page);
        $this->assertStringContainsString('Average Throughput', $page);
        $this->assertStringContainsString('Peak Throughput', $page);
        $this->assertStringContainsString('Lowest Usage', $page);
        $this->assertStringContainsString('Unmapped network identity', $page);
        $this->assertStringContainsString('Network / interface traffic', $page);
        $this->assertStringContainsString('not customer totals', $page);
        $this->assertStringContainsString('AUTHORITATIVE_TRAFFIC_UNAVAILABLE', $page);
    }
}
