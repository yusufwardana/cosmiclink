<?php

namespace Tests\Unit;

use App\Models\Router;
use App\Models\Tenant;
use App\Services\Network\GoNetworkMonitoringClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoNetworkMonitoringClientTest extends TestCase
{
    public function test_it_posts_read_only_router_credentials_and_sanitizes_response_secrets(): void
    {
        config()->set('network.go.url', 'http://network-engine.test');
        config()->set('network.go.token', 'shared-test-token');
        config()->set('network.routeros', ['transport' => 'api_ssl', 'connect_timeout_seconds' => 3, 'read_timeout_seconds' => 5, 'insecure_tls' => false]);
        $router = Router::factory()->for(Tenant::factory())->make(['host' => 'router.test', 'api_port' => 8729, 'username' => 'readonly']);
        $router->setPassword('router-test-password');

        Http::fake(function (Request $request) {
            $this->assertSame('Bearer shared-test-token', $request->header('Authorization')[0]);
            $this->assertSame('http://network-engine.test/api/v1/monitoring/collect', $request->url());
            $this->assertSame('router-test-password', data_get($request->data(), 'router.password'));

            return Http::response(['reachable' => true, 'identity' => 'edge-01', 'ppp_active' => [['name' => 'alice', 'secret' => 'must-not-return']]]);
        });

        $result = app(GoNetworkMonitoringClient::class)->collect($router);

        $this->assertTrue($result['reachable']);
        $this->assertArrayNotHasKey('secret', $result['ppp_active'][0]);
        $this->assertStringNotContainsString('router-test-password', json_encode($result));
    }

    public function test_engine_failure_is_bounded_and_secret_free(): void
    {
        config()->set('network.go.url', 'http://network-engine.test');
        config()->set('network.go.token', 'shared-test-token');
        Http::fake(['network-engine.test/*' => Http::response(['reachable' => false, 'failure' => ['code' => 'ROUTER_TIMEOUT', 'message' => 'safe']])]);

        $result = app(GoNetworkMonitoringClient::class)->collect(Router::factory()->for(Tenant::factory())->make());

        $this->assertFalse($result['reachable']);
        $this->assertSame('ROUTER_TIMEOUT', $result['failure']['code']);
    }

    public function test_migrated_monitoring_requests_only_an_exact_observer_reference(): void
    {
        config()->set('network.go.url', 'http://network-engine.test');
        config()->set('network.go.token', 'shared-test-token');
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

            return Http::response(['reachable' => true, 'identity' => 'edge-01']);
        });

        $this->assertTrue(app(GoNetworkMonitoringClient::class)->collect($router)['reachable']);
    }
}
