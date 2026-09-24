<?php

namespace App\Services\Network;

use App\Models\Router;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class GoNetworkMonitoringClient
{
    public function collect(Router $router): array
    {
        $payload = $this->payloadFor($router);
        if (isset($payload['failure'])) {
            return $payload;
        }

        return $this->post('/api/v1/monitoring/collect', $payload);
    }

    public function collectTraffic(Router $router, bool $includeEnrichment): array
    {
        $payload = $this->payloadFor($router);
        if (isset($payload['failure'])) {
            return $payload;
        }
        $payload['include_enrichment'] = $includeEnrichment;

        return $this->post('/api/v1/monitoring/traffic/collect', $payload);
    }

    public function validateHotspotAccounts(Router $router): array
    {
        $payload = $this->payloadFor($router);
        if (isset($payload['failure'])) {
            return $payload;
        }

        return $this->post('/api/v1/monitoring/hotspot-accounts', $payload, false);
    }

    private function payloadFor(Router $router): array
    {
        if ($router->observer_migration_state === 'LOCAL_OBSERVER_ACTIVE') {
            try {
                $reference = CredentialReference::fromRouter($router);
                if ($reference->purpose !== CredentialPurpose::OBSERVER) {
                    throw CredentialReferenceException::invalid();
                }
                $payload = $reference->toArray();
                $payload['credential_purpose'] = $payload['purpose'];
                unset($payload['purpose']);
                $payload['credential_version'] = $payload['version'];
                unset($payload['version']);
                $payload += ['host' => $router->host, 'port' => (int) $router->api_port, 'transport' => config('network.routeros.transport'), 'connect_timeout_seconds' => (int) config('network.routeros.connect_timeout_seconds'), 'read_timeout_seconds' => (int) config('network.routeros.read_timeout_seconds'), 'insecure_tls' => (bool) config('network.routeros.insecure_tls')];
            } catch (CredentialReferenceException) {
                return ['reachable' => false, 'failure' => ['code' => 'CREDENTIAL_REFERENCE_INVALID', 'message' => 'Observer credential reference is invalid.']];
            }
        } else {
            $payload = ['router' => [
                'host' => $router->host,
                'port' => (int) $router->api_port,
                'username' => $router->username,
                'password' => $router->password(),
                'transport' => config('network.routeros.transport'),
                'connect_timeout_seconds' => (int) config('network.routeros.connect_timeout_seconds'),
                'read_timeout_seconds' => (int) config('network.routeros.read_timeout_seconds'),
                'insecure_tls' => (bool) config('network.routeros.insecure_tls'),
            ]];
        }

        return $payload;
    }

    private function post(string $path, array $payload, bool $requiresReachability = true): array
    {
        try {
            $response = Http::acceptJson()->asJson()
                ->withToken((string) config('network.go.token'))
                ->connectTimeout((int) config('network.go.connect_timeout_seconds'))
                ->timeout((int) config('network.go.timeout_seconds'))
                ->post(rtrim((string) config('network.go.url'), '/').$path, $payload);
        } catch (ConnectionException $exception) {
            return ['reachable' => false, 'failure' => ['code' => str_contains(strtolower($exception->getMessage()), 'timed out') ? 'MONITORING_TIMEOUT' : 'ENGINE_UNAVAILABLE', 'message' => 'Go Network Engine is unavailable.']];
        } catch (Throwable) {
            return ['reachable' => false, 'failure' => ['code' => 'ENGINE_UNAVAILABLE', 'message' => 'Go Network Engine monitoring failed.']];
        }

        $body = $response->json();
        if (! is_array($body) || ($requiresReachability && (! array_key_exists('reachable', $body) || ! is_bool($body['reachable'])))) {
            return ['reachable' => false, 'failure' => ['code' => 'ENGINE_UNAVAILABLE', 'message' => 'Go Network Engine returned an invalid monitoring response.']];
        }

        return $this->sanitize($body);
    }

    private function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), ['password', 'pass', 'secret', 'token', 'authorization', 'credential', 'credentials'], true)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }

        return $data;
    }
}
