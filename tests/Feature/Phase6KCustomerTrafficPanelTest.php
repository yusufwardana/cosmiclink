<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\NetworkAccount;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Monitoring\TrafficCollectionService;
use App\Services\Network\GoNetworkMonitoringClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class Phase6KCustomerTrafficPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(GoNetworkMonitoringClient::class, Mockery::mock(GoNetworkMonitoringClient::class, fn ($mock) => $mock->shouldNotReceive('collectTraffic')));
        $this->app->instance(TrafficCollectionService::class, Mockery::mock(TrafficCollectionService::class, fn ($mock) => $mock->shouldNotReceive('collectRouter')->shouldNotReceive('runScheduled')));
    }

    public function test_customer_page_requires_authorization_and_exposes_compact_mapped_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create();
        $router = Router::factory()->for($tenant)->create();
        $customer = Customer::factory()->for($tenant)->create();
        $account = NetworkAccount::create(['tenant_id' => $tenant->id, 'router_id' => $router->id, 'username' => 'Panel-Client ', 'profile' => 'home', 'status' => 'active']);
        $connection = CustomerConnection::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'router_id' => $router->id,
            'network_account_id' => $account->id,
        ]);
        $unmapped = CustomerConnection::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'router_id' => Router::factory()->for($tenant)->create()->id,
            'network_account_id' => null,
        ]);

        $foreign = Tenant::factory()->create();
        $foreignCustomer = Customer::factory()->for($foreign)->create();

        $this->get(route('customers.show', $customer))->assertRedirect('/login');
        $this->actingAs($user)->get(route('customers.show', $foreignCustomer))->assertForbidden();

        $response = $this->actingAs($user)->get(route('customers.show', $customer));

        $response->assertOk()
            ->assertSee('id="customer-traffic-vue"', false)
            ->assertSee('Today')
            ->assertSee('7 Days')
            ->assertSee('30 Days')
            ->assertSee('no mapped identity')
            ->assertSee('panel-client')
            ->assertSee((string) $connection->id);

        $page = $response->getContent();
        $unmappedNeedle = 'value="'.$unmapped->id.'"';
        $this->assertStringNotContainsString($unmappedNeedle, $page);

        $panel = file_get_contents(resource_path('js/modules/traffic/CustomerTrafficPanel.vue'));
        $this->assertStringContainsString('Today', $panel);
        $this->assertStringContainsString('7 Days', $panel);
        $this->assertStringContainsString('30 Days', $panel);
        $this->assertStringContainsString('subscriber-history', $panel);
        $this->assertStringContainsString('STATIC_SIMPLE_QUEUE', $panel);
        $this->assertStringContainsString('HOTSPOT', $panel);
        $this->assertStringNotContainsString('interface-history', $panel);
        $this->assertStringNotContainsString('PPPOE', $panel);
    }
}
