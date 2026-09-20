<?php

namespace App\Services\Network;

use App\Models\Router;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use LogicException;
use Throwable;

class GoNetworkDiscoveryClient implements NetworkDiscoveryClient
{
    public function discover(Router $router): DiscoveryResult
    {
        $payload = $this->payload($router);
        try {
            $response = Http::acceptJson()->asJson()->withToken((string) config('network.go.token'))->connectTimeout((int) config('network.go.connect_timeout_seconds'))->timeout((int) config('network.go.timeout_seconds'))->post(rtrim((string) config('network.go.url'), '/').'/v1/discovery/routers/'.$router->id, $payload);
        } catch (ConnectionException $e) {
            return new DiscoveryResult(false, str_contains(strtolower($e->getMessage()), 'timed out') ? 'Go Network Engine discovery timed out.' : 'Go Network Engine is unavailable.', str_contains(strtolower($e->getMessage()), 'timed out') ? 'NETWORK_ENGINE_TIMEOUT' : 'NETWORK_ENGINE_UNAVAILABLE');
        } catch (Throwable) {
            return new DiscoveryResult(false, 'Go Network Engine discovery failed.', 'NETWORK_ENGINE_UNAVAILABLE');
        } $body = $response->json();
        if (! is_array($body) || ! isset($body['success']) || ! is_bool($body['success']) || ! isset($body['message']) || ! is_string($body['message'])) {
            return new DiscoveryResult(false, 'Go Network Engine returned an invalid discovery response.', 'NETWORK_ENGINE_INVALID_RESPONSE');
        }

        return new DiscoveryResult($body['success'], $body['message'], $body['success'] ? null : ($body['code'] ?? 'NETWORK_ENGINE_DISCOVERY_FAILED'), $this->sanitize($body));
    }

    private function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->sensitiveKey((string) $key)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }

        return $data;
    }

    private function payload(Router $router): array
    {
        $payload = ['tenant_ref' => (string) $router->tenant_id, 'router_ref' => (string) $router->id];
        if ($router->observer_migration_state === 'LOCAL_OBSERVER_ACTIVE') {
            $reference = CredentialReference::fromRouter($router);
            if ($reference->purpose !== CredentialPurpose::OBSERVER) {
                throw CredentialReferenceException::invalid();
            }

            return array_merge($payload, [
                'agent_ref' => $reference->agentRef,
                'installation_id' => $reference->installationId,
                'credential_ref' => $reference->credentialRef,
                'credential_purpose' => $reference->purpose->value,
                'credential_version' => $reference->version,
                'host' => $router->host,
                'port' => (int) $router->api_port,
                'transport' => config('network.routeros.transport'),
                'connect_timeout_seconds' => (int) config('network.routeros.connect_timeout_seconds'),
                'read_timeout_seconds' => (int) config('network.routeros.read_timeout_seconds'),
                'insecure_tls' => (bool) config('network.routeros.insecure_tls'),
            ]);
        }
        if (config('network.discovery_provider') === 'routeros') {
            if ((bool) config('network.routeros.insecure_tls') && ! app()->environment(['local', 'testing'])) {
                throw new LogicException('Insecure RouterOS TLS is allowed only in local or testing environments.');
            }
            $payload['connection'] = ['host' => $router->host, 'port' => (int) $router->api_port, 'username' => $router->username, 'password' => $router->password(), 'transport' => config('network.routeros.transport'), 'connect_timeout_seconds' => (int) config('network.routeros.connect_timeout_seconds'), 'read_timeout_seconds' => (int) config('network.routeros.read_timeout_seconds'), 'insecure_tls' => (bool) config('network.routeros.insecure_tls')];
        }

        return $payload;
    }

    private function sensitiveKey(string $key): bool
    {
        return in_array(strtolower($key), ['password', 'pass', 'secret', 'token', 'authorization', 'credential', 'credentials'], true);
    }
}
