<?php

namespace Tests\Unit;

use App\Models\Router;
use App\Models\Tenant;
use App\Services\Network\GoNetworkDiscoveryClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoNetworkDiscoveryClientTest extends TestCase
{
    public function test_it_requests_a_secret_free_authenticated_normalized_snapshot(): void
    {
        config()->set('network.go.url', 'http://network-engine.test');
        config()->set('network.go.token', 'shared-test-token');
        $router = Router::factory()->for(Tenant::factory())->make(['id' => 42, 'tenant_id' => 7]);
        Http::fake(function (Request $request) {
            $this->assertSame('Bearer shared-test-token', $request->header('Authorization')[0]);
            $this->assertSame('http://network-engine.test/v1/discovery/routers/42', $request->url());
            $this->assertSame(['tenant_ref' => '7', 'router_ref' => '42'], $request->data());

            return Http::response(['success' => true, 'provider' => 'fake', 'router_ref' => '42', 'discovered_at' => '2026-09-17T00:00:00Z', 'code' => 'DISCOVERY_COMPLETE', 'message' => 'Read-only simulated network discovery completed', 'snapshot' => ['accounts' => [['username' => 'existing-user-001', 'profile' => '10M', 'password' => 'never-return']], 'profiles' => [], 'address_pools' => [], 'queues' => []]]);
        });

        $result = app(GoNetworkDiscoveryClient::class)->discover($router);

        $this->assertTrue($result->successful);
        $this->assertSame('existing-user-001', $result->data['snapshot']['accounts'][0]['username']);
        $this->assertArrayNotHasKey('password', $result->data['snapshot']['accounts'][0]);
    }

    public function test_it_maps_malformed_and_failed_responses_safely(): void
    {
        config()->set('network.go.url', 'http://network-engine.test');
        config()->set('network.go.token', 'shared-test-token');
        $router = Router::factory()->for(Tenant::factory())->make(['id' => 42, 'tenant_id' => 7]);
        Http::fake(['network-engine.test/*' => Http::response(['message' => 'bad contract'])]);

        $result = app(GoNetworkDiscoveryClient::class)->discover($router);

        $this->assertFalse($result->successful);
        $this->assertSame('NETWORK_ENGINE_INVALID_RESPONSE', $result->errorCode);
    }
}
