<?php

namespace App\Services\Network;

use App\Models\Router;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class GoNetworkDiscoveryClient implements NetworkDiscoveryClient
{
    public function discover(Router $router): DiscoveryResult
    {
        try {
            $response = Http::acceptJson()->asJson()->withToken((string) config('network.go.token'))->connectTimeout((int) config('network.go.connect_timeout_seconds'))->timeout((int) config('network.go.timeout_seconds'))->post(rtrim((string) config('network.go.url'), '/').'/v1/discovery/routers/'.$router->id, ['tenant_ref' => (string) $router->tenant_id, 'router_ref' => (string) $router->id]);
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
            if (in_array(strtolower((string) $key), ['password', 'secret', 'token', 'credentials'], true)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }

return $data;
    }
}
