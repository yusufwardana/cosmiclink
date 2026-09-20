<?php

namespace Tests\Unit;

use App\Models\NetworkAccount;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\Network\ControlledNetworkExecution;
use App\Services\Network\FakeNetworkDriver;
use App\Services\Network\GoNetworkDriver;
use App\Services\Network\NetworkDriver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoNetworkDriverTest extends TestCase
{
    public function test_network_driver_configuration_preserves_fake_and_resolves_go(): void
    {
        config()->set('network.driver', 'fake');
        $this->assertInstanceOf(FakeNetworkDriver::class, app(NetworkDriver::class));

        app()->forgetInstance(NetworkDriver::class);
        config()->set('network.driver', 'go');
        $this->assertInstanceOf(GoNetworkDriver::class, app(NetworkDriver::class));
    }

    public function test_it_sends_an_authenticated_normalized_create_request_without_exposing_the_password_in_result_data(): void
    {
        config()->set('network.go.url', 'http://network-engine.test');
        config()->set('network.go.token', 'shared-test-token');
        $router = Router::factory()->for(Tenant::factory())->make(['id' => 42, 'tenant_id' => 7]);

        Http::fake(function (Request $request) {
            $this->assertSame('Bearer shared-test-token', $request->header('Authorization')[0]);
            $this->assertSame('http://network-engine.test/v1/network/accounts', $request->url());
            $this->assertSame('CREATE_PPPOE', $request['operation']);
            $this->assertSame('7', $request['tenant_ref']);
            $this->assertSame('42', $request['router_ref']);
            $this->assertSame('cust001', $request['account_ref']);
            $this->assertNotEmpty($request['operation_id']);
            $this->assertNotEmpty($request['idempotency_key']);
            $this->assertSame('pppoe-secret', $request['parameters']['password']);

            return Http::response([
                'success' => true,
                'operation_id' => $request['operation_id'],
                'provider' => 'fake',
                'code' => 'ACCOUNT_CREATED',
                'message' => 'Network account created',
                'data' => ['username' => 'cust001', 'password' => 'pppoe-secret'],
            ]);
        });

        $result = app(GoNetworkDriver::class)->createPppoeAccount($router, [
            'username' => 'cust001',
            'profile' => 'HOME-10M',
            'password' => 'pppoe-secret',
        ]);

        $this->assertTrue($result->successful);
        $this->assertSame('Network account created', $result->message);
        $this->assertSame('cust001', $result->data['username']);
        $this->assertArrayNotHasKey('password', $result->data);
        $this->assertSame('ACCOUNT_CREATED', $result->data['code']);
    }

    public function test_it_maps_a_structured_engine_failure(): void
    {
        config()->set('network.go.url', 'http://network-engine.test');
        config()->set('network.go.token', 'shared-test-token');
        $router = Router::factory()->for(Tenant::factory())->make(['id' => 42, 'tenant_id' => 7]);
        Http::fake(['network-engine.test/*' => Http::response([
            'success' => false,
            'operation_id' => 'operation-1',
            'provider' => 'fake',
            'code' => 'ACCOUNT_NOT_FOUND',
            'message' => 'Network account not found',
        ], 404)]);

        $result = app(GoNetworkDriver::class)->disablePppoeAccount($router, 'missing');

        $this->assertFalse($result->successful);
        $this->assertSame('ACCOUNT_NOT_FOUND', $result->errorCode);
        $this->assertSame('Network account not found', $result->message);
    }

    public function test_it_serializes_empty_operation_parameters_as_a_json_object(): void
    {
        config()->set('network.go.url', 'http://network-engine.test');
        config()->set('network.go.token', 'shared-test-token');
        $router = Router::factory()->for(Tenant::factory())->make(['id' => 42, 'tenant_id' => 7]);

        Http::fake(function (Request $request) {
            $payload = json_decode($request->body());
            $this->assertIsObject($payload->parameters);

            return Http::response([
                'success' => true,
                'operation_id' => $request['operation_id'],
                'provider' => 'fake',
                'code' => 'ACCOUNT_DISABLED',
                'message' => 'Network account disabled',
            ]);
        });

        $result = app(GoNetworkDriver::class)->disablePppoeAccount($router, 'cust001');

        $this->assertTrue($result->successful);
    }

    public function test_controlled_execution_forwards_authoritative_identities_and_operator_reference_unchanged(): void
    {
        config()->set('network.go.url', 'http://network-engine.test');
        config()->set('network.go.token', 'shared-test-token');
        $router = Router::factory()->for(Tenant::factory())->make(['id' => 42, 'tenant_id' => 7, 'host' => 'router.test', 'api_port' => 8728]);
        $account = new NetworkAccount(['username' => 'cust001']);
        $account->setRelation('router', $router);
        $execution = new ControlledNetworkExecution(
            operation: 'DISABLE_PPPOE',
            idempotencyKey: 'laravel-authoritative-key',
            requestDigest: str_repeat('a', 64),
            executionId: 'network-operation-exact-1',
            agentRef: 'agent-1',
            installationId: 'install-1',
            credentialRef: 'operator-ref',
            credentialVersion: 3,
            targetIdentityRef: '*7',
            fencingRef: 'reservation-1',
        );

        Http::fake(function (Request $request) use ($execution) {
            $this->assertSame('laravel-authoritative-key', $request['idempotency_key']);
            $this->assertSame(str_repeat('a', 64), $request['request_digest']);
            $this->assertSame('network-operation-exact-1', $request['execution_id']);
            $this->assertSame('agent-1', $request['agent_ref']);
            $this->assertSame('install-1', $request['installation_id']);
            $this->assertSame('operator-ref', $request['credential_ref']);
            $this->assertSame('OPERATOR', $request['credential_purpose']);
            $this->assertSame(3, $request['credential_version']);
            $this->assertSame('*7', $request['target_identity_ref']);
            $this->assertArrayNotHasKey('password', $request->data());

            return Http::response(['success' => true, 'operation_id' => $execution->executionId, 'provider' => 'fake', 'code' => 'ACCOUNT_DISABLED', 'message' => 'ok']);
        });

        $result = app(GoNetworkDriver::class)->executeControlled($router, $account, $execution);

        $this->assertTrue($result->successful);
    }

    public function test_it_maps_connection_and_timeout_failures_without_leaking_the_service_token(): void
    {
        config()->set('network.go.url', 'http://network-engine.test');
        config()->set('network.go.token', 'shared-test-token');
        $router = Router::factory()->for(Tenant::factory())->make(['id' => 42, 'tenant_id' => 7]);
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 1000 milliseconds'));

        $result = app(GoNetworkDriver::class)->testConnection($router);

        $this->assertFalse($result->successful);
        $this->assertSame('NETWORK_ENGINE_TIMEOUT', $result->errorCode);
        $this->assertStringNotContainsString('shared-test-token', $result->message);
    }

    public function test_it_rejects_malformed_engine_responses_safely(): void
    {
        config()->set('network.go.url', 'http://network-engine.test');
        config()->set('network.go.token', 'shared-test-token');
        $router = Router::factory()->for(Tenant::factory())->make(['id' => 42, 'tenant_id' => 7]);
        Http::fake(['network-engine.test/*' => Http::response(['message' => 'bad contract'])]);

        $result = app(GoNetworkDriver::class)->enablePppoeAccount($router, 'cust001');

        $this->assertFalse($result->successful);
        $this->assertSame('NETWORK_ENGINE_INVALID_RESPONSE', $result->errorCode);
    }
}
