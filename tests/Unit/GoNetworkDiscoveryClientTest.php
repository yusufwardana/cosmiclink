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
        config()->set('network.discovery_provider', 'fake');
        $router = Router::factory()->for(Tenant::factory())->make(['id' => 42, 'tenant_id' => 7, 'host' => 'router.test', 'api_port' => 8729]);
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

    public function test_routeros_credentials_are_decrypted_only_for_the_server_to_server_request_and_never_returned(): void
    {
        config()->set('network.go.url', 'http://network-engine.test');
        config()->set('network.go.token', 'shared-test-token');
        config()->set('network.discovery_provider', 'routeros');
        config()->set('network.routeros', ['transport' => 'api_ssl', 'connect_timeout_seconds' => 3, 'read_timeout_seconds' => 5, 'insecure_tls' => false]);
        $router = Router::factory()->for(Tenant::factory())->make(['id' => 42, 'tenant_id' => 7, 'host' => 'router.test', 'api_port' => 8729, 'username' => 'readonly']);
        $router->setPassword('router-test-password');
        Http::fake(function (Request $request) {
            $this->assertSame(['tenant_ref' => '7', 'router_ref' => '42', 'connection' => ['host' => 'router.test', 'port' => 8729, 'username' => 'readonly', 'password' => 'router-test-password', 'transport' => 'api_ssl', 'connect_timeout_seconds' => 3, 'read_timeout_seconds' => 5, 'insecure_tls' => false]], $request->data());

            return Http::response(['success' => true, 'provider' => 'routeros', 'router_ref' => '42', 'discovered_at' => '2026-09-17T00:00:00Z', 'code' => 'DISCOVERY_COMPLETE', 'message' => 'Read-only RouterOS discovery completed', 'snapshot' => ['accounts' => [['username' => 'alice', 'pass' => 'never-return', 'authorization' => 'never-return']], 'profiles' => [], 'address_pools' => [], 'queues' => []]]);
        });

        $result = app(GoNetworkDiscoveryClient::class)->discover($router);

        $this->assertTrue($result->successful);
        $this->assertArrayNotHasKey('pass', $result->data['snapshot']['accounts'][0]);
        $this->assertArrayNotHasKey('authorization', $result->data['snapshot']['accounts'][0]);
        $this->assertStringNotContainsString('router-test-password', json_encode($result->data));
    }

    public function test_migrated_router_requests_only_an_exact_observer_reference(): void
    {
        config()->set('network.go.url', 'http://network-engine.test');
        config()->set('network.go.token', 'shared-test-token');
        config()->set('network.discovery_provider', 'routeros');
        $router = Router::factory()->for(Tenant::factory())->make(['id' => 42, 'tenant_id' => 7, 'host' => 'router.test', 'api_port' => 8729]);
        $router->forceFill(['observer_agent_ref' => 'agent-1', 'observer_installation_id' => 'installation-1', 'observer_credential_ref' => 'cred-1', 'observer_credential_purpose' => 'OBSERVER', 'observer_credential_version' => 2, 'observer_credential_status' => 'ACTIVE', 'observer_migration_state' => 'LOCAL_OBSERVER_ACTIVE']);

        Http::fake(function (Request $request) {
            $this->assertSame([
                'tenant_ref' => '7',
                'router_ref' => '42',
                'agent_ref' => 'agent-1',
                'installation_id' => 'installation-1',
                'credential_ref' => 'cred-1',
                'credential_purpose' => 'OBSERVER',
                'credential_version' => 2,
                'host' => 'router.test',
                'port' => 8729,
                'transport' => 'api_ssl',
                'connect_timeout_seconds' => 3,
                'read_timeout_seconds' => 5,
                'insecure_tls' => false,
            ], $request->data());
            $this->assertStringNotContainsString('password', strtolower($request->body()));

            return Http::response(['success' => true, 'provider' => 'routeros', 'router_ref' => '42', 'message' => 'ok']);
        });

        $this->assertTrue(app(GoNetworkDiscoveryClient::class)->discover($router)->successful);
    }

    public function test_it_maps_malformed_and_failed_responses_safely(): void
    {
        config()->set('network.go.url', 'http://network-engine.test');
        config()->set('network.go.token', 'shared-test-token');
        config()->set('network.discovery_provider', 'fake');
        $router = Router::factory()->for(Tenant::factory())->make(['id' => 42, 'tenant_id' => 7]);
        Http::fake(['network-engine.test/*' => Http::response(['message' => 'bad contract'])]);

        $result = app(GoNetworkDiscoveryClient::class)->discover($router);

        $this->assertFalse($result->successful);
        $this->assertSame('NETWORK_ENGINE_INVALID_RESPONSE', $result->errorCode);
    }
}
