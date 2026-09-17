<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\DiscoveredNetworkResource;
use App\Models\InternetPackage;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\NetworkDiscoveryClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Phase6CLiveDiscoveryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_laravel_to_go_discovery_repeat_adoption_and_zero_mutation_proof(): void
    {
        if (env('NETWORK_ENGINE_LIVE_TEST') !== '1') {
            $this->markTestSkipped('Set NETWORK_ENGINE_LIVE_TEST=1 to run this test against a running local Go Network Engine.');
        }

        config()->set('network.go.url', env('GO_NETWORK_ENGINE_URL', 'http://127.0.0.1:8787'));
        config()->set('network.go.token', env('GO_NETWORK_ENGINE_TOKEN'));
        app()->forgetInstance(NetworkDiscoveryClient::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create();
        $router = Router::factory()->for($tenant)->create();
        $connection = CustomerConnection::factory()->for(Customer::factory()->for($tenant))->for(InternetPackage::factory()->for($tenant))->for($router)->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->post(route('network.discovery.run', $router))->assertRedirect();
        $this->assertDatabaseCount('network_discovery_snapshots', 1);
        $this->assertDatabaseCount('discovered_network_resources', 8);
        $resource = DiscoveredNetworkResource::where('tenant_id', $tenant->id)->where('resource_type', 'pppoe_account')->firstOrFail();
        $this->actingAs($user)->post(route('network.discovery.adopt', $resource), ['customer_connection_id' => $connection->id])->assertRedirect();
        $this->assertSame('ADOPTED', $resource->fresh()->management_state);
        $this->actingAs($user)->post(route('network.discovery.run', $router))->assertRedirect();
        $this->assertDatabaseCount('discovered_network_resources', 8);
        $this->assertSame('ADOPTED', $resource->fresh()->management_state);
        $this->assertDatabaseCount('network_operation_logs', 0);

        $response = Http::withToken((string) config('network.go.token'))->get(rtrim((string) config('network.go.url'), '/').'/v1/discovery/safety/mutation-count');
        $this->assertTrue($response->successful());
        $this->assertSame(0, $response->json('mutation_count'));
    }
}
