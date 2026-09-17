<?php

namespace App\Services\Network;

use App\Models\Router;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class GoNetworkDriver implements NetworkDriver
{
    public function testConnection(Router $router): NetworkOperationResult
    {
        return $this->execute('TEST_CONNECTION', '/v1/network/routers/test', $router);
    }

    public function createPppoeAccount(Router $router, array $account): NetworkOperationResult
    {
        return $this->execute('CREATE_PPPOE', '/v1/network/accounts', $router, (string) $account['username'], [
            'username' => $account['username'],
            'profile' => $account['profile'],
            'password' => $account['password'] ?? null,
        ]);
    }

    public function enablePppoeAccount(Router $router, string $username): NetworkOperationResult
    {
        return $this->execute('ENABLE_PPPOE', '/v1/network/accounts/'.rawurlencode($username).'/enable', $router, $username);
    }

    public function disablePppoeAccount(Router $router, string $username): NetworkOperationResult
    {
        return $this->execute('DISABLE_PPPOE', '/v1/network/accounts/'.rawurlencode($username).'/disable', $router, $username);
    }

    public function changePppoeProfile(Router $router, string $username, string $profile): NetworkOperationResult
    {
        return $this->execute('CHANGE_PROFILE', '/v1/network/accounts/'.rawurlencode($username).'/profile', $router, $username, ['profile' => $profile]);
    }

    public function disconnectPppoeSession(Router $router, string $username): NetworkOperationResult
    {
        return $this->execute('DISCONNECT_SESSION', '/v1/network/accounts/'.rawurlencode($username).'/disconnect', $router, $username);
    }

    private function execute(string $operation, string $path, Router $router, ?string $accountReference = null, array $parameters = []): NetworkOperationResult
    {
        $operationId = (string) Str::uuid();
        $parameters = array_filter($parameters, static fn (mixed $value): bool => $value !== null);
        $requestParameters = $parameters === [] ? (object) [] : $parameters;
        $payload = [
            'operation_id' => $operationId,
            'idempotency_key' => hash('sha256', implode('|', [$operation, $router->tenant_id, $router->id, $accountReference ?? '', json_encode($parameters) ?: ''])),
            'operation' => $operation,
            'tenant_ref' => (string) $router->tenant_id,
            'router_ref' => (string) $router->id,
            'account_ref' => $accountReference,
            'parameters' => $requestParameters,
        ];

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken((string) config('network.go.token'))
                ->connectTimeout((int) config('network.go.connect_timeout_seconds'))
                ->timeout((int) config('network.go.timeout_seconds'))
                ->post(rtrim((string) config('network.go.url'), '/').$path, $payload);
        } catch (ConnectionException $exception) {
            $timeout = str_contains(strtolower($exception->getMessage()), 'timed out');

            return new NetworkOperationResult(false, $timeout ? 'Go Network Engine request timed out.' : 'Go Network Engine is unavailable.', $timeout ? 'NETWORK_ENGINE_TIMEOUT' : 'NETWORK_ENGINE_UNAVAILABLE');
        } catch (Throwable) {
            return new NetworkOperationResult(false, 'Go Network Engine request failed.', 'NETWORK_ENGINE_UNAVAILABLE');
        }

        $body = $response->json();
        if (! is_array($body) || ! array_key_exists('success', $body) || ! is_bool($body['success']) || ! isset($body['message']) || ! is_string($body['message'])) {
            return new NetworkOperationResult(false, 'Go Network Engine returned an invalid response.', 'NETWORK_ENGINE_INVALID_RESPONSE');
        }

        $data = $this->sanitize(is_array($body['data'] ?? null) ? $body['data'] : []);
        if (isset($body['code']) && is_string($body['code'])) {
            $data['code'] = $body['code'];
        }
        if (isset($body['provider']) && is_string($body['provider'])) {
            $data['provider'] = $body['provider'];
        }
        if (isset($body['operation_id']) && is_string($body['operation_id'])) {
            $data['operation_id'] = $body['operation_id'];
        }

        return new NetworkOperationResult($body['success'], $body['message'], $body['success'] ? null : ($body['code'] ?? 'NETWORK_ENGINE_OPERATION_FAILED'), $data);
    }

    private function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), ['password', 'secret', 'token', 'credentials'], true)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }

        return $data;
    }
}
