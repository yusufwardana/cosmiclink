<?php

namespace App\Services\Monitoring;

use App\Models\Router;
use App\Models\RouterCapabilitySnapshot;

final class RouterCapabilityService
{
    public const READ_CAPABILITIES = [
        'system.read',
        'interfaces.read',
        'traffic.interface.read',
        'queue.simple.read',
        'pppoe.sessions.read',
        'hotspot.sessions.read',
        'arp.read',
        'dhcp.leases.read',
    ];

    public function refreshFromMonitoring(Router $router, array $payload): RouterCapabilitySnapshot
    {
        $version = $this->normalizeVersion($payload['version'] ?? null);
        $major = $version ? (int) explode('.', $version)[0] : null;

        $capabilities = [];
        foreach (self::READ_CAPABILITIES as $capability) {
            $capabilities[$capability] = in_array($major, [6, 7], true) ? 'supported' : 'unknown';
        }

        return RouterCapabilitySnapshot::create([
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'routeros_version' => $version,
            'routeros_major' => $major,
            'architecture' => $this->text($payload['architecture'] ?? null),
            'board' => $this->text($payload['board'] ?? null),
            'capabilities' => $capabilities,
            'source' => 'monitoring',
            'verified_at' => now(),
        ]);
    }

    public function supports(Router $router, string $capability): bool
    {
        $snapshot = $router->capabilitySnapshots()->latest('verified_at')->first();

        return ($snapshot?->capabilities[$capability] ?? 'unknown') === 'supported';
    }

    private function normalizeVersion(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if (! preg_match('/^(\d+\.\d+\.\d+)(?: \([A-Za-z0-9][A-Za-z0-9.-]*\))?$/D', $raw, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
