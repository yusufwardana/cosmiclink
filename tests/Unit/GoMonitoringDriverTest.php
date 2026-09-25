<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\InternetPackage;
use App\Models\NetworkAccount;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\Monitoring\GoMonitoringDriver;
use App\Services\Monitoring\HealthState;
use App\Services\Monitoring\RouterCapabilityService;
use App\Services\Network\GoNetworkMonitoringClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoMonitoringDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_mapped_connection_is_online_only_when_its_explicit_account_is_active(): void
    {
        $tenant = Tenant::factory()->create();
        $router = Router::factory()->for($tenant)->create();
        $customer = Customer::factory()->for($tenant)->create();
        $package = InternetPackage::factory()->for($tenant)->create();
        $account = NetworkAccount::create(['tenant_id' => $tenant->id, 'router_id' => $router->id, 'username' => 'alice', 'profile' => '10M', 'status' => 'active']);
        $connection = CustomerConnection::factory()->for($customer)->for($package)->for($router)->create(['tenant_id' => $tenant->id, 'network_account_id' => $account->id, 'status' => 'active', 'provisioned_at' => now()]);
        $client = new class extends GoNetworkMonitoringClient
        {
            public function collect(Router $router): array
            {
                return ['reachable' => true, 'ppp_active' => [['name' => 'alice', 'service' => 'pppoe']]];
            }
        };

        $result = (new GoMonitoringDriver($client, app(RouterCapabilityService::class)))->observeConnection($connection);

        $this->assertSame(HealthState::ONLINE, $result->state);
    }

    public function test_engine_failure_never_becomes_customer_offline(): void
    {
        $client = new class extends GoNetworkMonitoringClient
        {
            public function collect(Router $router): array
            {
                return ['reachable' => false, 'failure' => ['code' => 'ENGINE_UNAVAILABLE']];
            }
        };
        $connection = CustomerConnection::factory()->create(['provisioned_at' => now()]);

        $result = (new GoMonitoringDriver($client, app(RouterCapabilityService::class)))->observeConnection($connection);

        $this->assertSame(HealthState::UNKNOWN, $result->state);
        $this->assertNull($result->online);
    }
}
