<?php

namespace App\Services\Network;

use App\Models\CustomerConnection;
use App\Models\DeviceObservation;
use App\Models\Router;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class DeviceObservationService
{
    public function collect(Router $router): array
    {
        $payload = [
            'tenant_ref' => (string) $router->tenant_id,
            'router_ref' => (string) $router->id,
            'agent_ref' => $router->observer_agent_ref,
            'installation_id' => $router->observer_installation_id,
            'credential_ref' => $router->observer_credential_ref,
            'credential_purpose' => CredentialPurpose::OBSERVER->value,
            'credential_version' => (int) $router->observer_credential_version,
            'host' => $router->host,
            'port' => (int) $router->api_port,
            'transport' => config('network.routeros.transport'),
            'connect_timeout_seconds' => (int) config('network.routeros.connect_timeout_seconds'),
            'read_timeout_seconds' => (int) config('network.routeros.read_timeout_seconds'),
            'insecure_tls' => (bool) config('network.routeros.insecure_tls'),
        ];

        $response = Http::acceptJson()
            ->asJson()
            ->withToken((string) config('network.go.token'))
            ->connectTimeout((int) config('network.go.connect_timeout_seconds'))
            ->timeout((int) config('network.go.timeout_seconds'))
            ->post(rtrim((string) config('network.go.url'), '/').'/api/v1/monitoring/hotspot-survey', $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Read-only device survey failed.');
        }

        return $response->json();
    }

    public function collectDhcpLeases(Router $router): array
    {
        $payload = [
            'tenant_ref' => (string) $router->tenant_id,
            'router_ref' => (string) $router->id,
            'agent_ref' => $router->observer_agent_ref,
            'installation_id' => $router->observer_installation_id,
            'credential_ref' => $router->observer_credential_ref,
            'credential_purpose' => CredentialPurpose::OBSERVER->value,
            'credential_version' => (int) $router->observer_credential_version,
            'host' => $router->host,
            'port' => (int) $router->api_port,
            'transport' => config('network.routeros.transport'),
            'connect_timeout_seconds' => (int) config('network.routeros.connect_timeout_seconds'),
            'read_timeout_seconds' => (int) config('network.routeros.read_timeout_seconds'),
            'insecure_tls' => (bool) config('network.routeros.insecure_tls'),
        ];

        $response = Http::acceptJson()
            ->asJson()
            ->withToken((string) config('network.go.token'))
            ->connectTimeout((int) config('network.go.connect_timeout_seconds'))
            ->timeout((int) config('network.go.timeout_seconds'))
            ->post(rtrim((string) config('network.go.url'), '/').'/api/v1/monitoring/dhcp-survey', $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Read-only DHCP survey failed.');
        }

        return $response->json();
    }

    public function persist(Router $router, array $survey): array
    {
        $observedAt = CarbonImmutable::parse($survey['surveyed_at'] ?? now());
        $connections = CustomerConnection::query()->where('tenant_id', $router->tenant_id)->where('router_id', $router->id)->get();

        return DB::transaction(function () use ($router, $survey, $observedAt, $connections): array {
            $seen = 0;
            foreach ((array) ($survey['arp_entries'] ?? []) as $entry) {
                $ip = trim((string) ($entry['address'] ?? ''));
                $mac = $this->mac($entry['mac_address'] ?? null);
                if ($ip === '' || $mac === '') continue;
                $identity = $this->staticIdentity($connections, $ip);
                $this->upsert($router, 'arp', $identity ?? $ip, $ip, $mac, $entry['interface'] ?? null, null, [
                    'complete' => (bool) ($entry['complete'] ?? false),
                    'dynamic' => (bool) ($entry['dynamic'] ?? false),
                ], $observedAt, $connections, $identity);
                $seen++;
            }

            foreach ((array) ($survey['active_sessions'] ?? []) as $entry) {
                $username = trim((string) ($entry['username'] ?? ''));
                $ip = trim((string) ($entry['address'] ?? ''));
                $mac = $this->mac($entry['mac_address'] ?? null);
                if ($username === '' || $ip === '' || $mac === '') continue;
                $identity = strtolower($username);
                $this->upsert($router, 'hotspot_active', $identity, $ip, $mac, null, $entry['server'] ?? null, [
                    'login_by' => $entry['login_by'] ?? null,
                    'uptime' => $entry['uptime'] ?? null,
                ], $observedAt, $connections, $identity);
                $seen++;
            }
            return ['observed' => $seen];
        });
    }

    public function enrichDhcp(Router $router, array $survey): array
    {
        $observedAt = CarbonImmutable::parse($survey['surveyed_at'] ?? now());
        $macs = DeviceObservation::query()->where('tenant_id', $router->tenant_id)->where('router_id', $router->id)->get()->keyBy('mac_address');
        $matched = 0;
        $leases = 0;

        DB::transaction(function () use ($router, $survey, $observedAt, $macs, &$matched, &$leases): void {
            foreach ((array) ($survey['leases'] ?? []) as $lease) {
                $leases++;
                $mac = $this->mac($lease['mac_address'] ?? null);
                if ($mac === '' || ! $macs->has($mac)) {
                    continue;
                }
                $observation = $macs->get($mac);
                $metadata = array_merge($observation->metadata ?? [], array_filter([
                    'dhcp_hostname' => trim((string) ($lease['host_name'] ?? '')) ?: null,
                    'dhcp_client_id' => $lease['client_id'] ?? null,
                    'dhcp_server' => $lease['server'] ?? null,
                    'dhcp_status' => $lease['status'] ?? null,
                    'dhcp_dynamic' => array_key_exists('dynamic', $lease) ? (bool) $lease['dynamic'] : null,
                    'dhcp_last_seen' => $lease['last_seen'] ?? null,
                    'dhcp_expires_after' => $lease['expires_after'] ?? null,
                ], fn ($value) => $value !== null && $value !== ''));
                $observation->update([
                    'metadata' => $metadata,
                    'last_seen_at' => $observedAt,
                ]);
                $matched++;
            }
        });

        return ['leases' => $leases, 'matched' => $matched];
    }

    private function upsert(Router $router, string $source, string $identity, string $ip, string $mac, ?string $interface, ?string $server, array $metadata, CarbonImmutable $observedAt, $connections, ?string $matchIdentity): void
    {
        $connection = $source === 'arp'
            ? $connections->first(fn (CustomerConnection $c) => $c->access_mode === 'static_ip' && trim((string) ($c->metadata['network_identity'] ?? '')) === $matchIdentity.'/32')
            : $connections->first(fn (CustomerConnection $c) => $c->access_mode === 'hotspot' && strtolower(trim((string) ($c->metadata['network_identity'] ?? ''))) === strtolower((string) $matchIdentity));
        $keys = ['tenant_id' => $router->tenant_id, 'router_id' => $router->id, 'source' => $source, 'network_identity' => $identity, 'ip_address' => $ip, 'mac_address' => $mac];
        $observation = DeviceObservation::query()->where($keys)->first();
        $values = ['customer_connection_id' => $connection?->id, 'interface' => $interface, 'server' => $server, 'metadata' => array_filter($metadata, fn ($value) => $value !== null), 'last_seen_at' => $observedAt];
        if ($observation) $observation->update($values); else DeviceObservation::create($keys + $values + ['first_seen_at' => $observedAt]);
    }

    private function staticIdentity($connections, string $ip): ?string
    {
        $connection = $connections->first(fn (CustomerConnection $c) => $c->access_mode === 'static_ip' && trim((string) ($c->metadata['network_identity'] ?? '')) === $ip.'/32');

        return $connection ? $ip : null;
    }

    private function mac(?string $mac): string
    {
        return strtoupper(trim((string) $mac));
    }
}