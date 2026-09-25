<?php

namespace App\Services\Monitoring;

use App\Models\Router;
use App\Models\RouterCapabilitySnapshot;
use Carbon\CarbonInterface;

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

    private const DATASET_CAPABILITIES = [
        'system_resource' => ['system.read'],
        'interfaces' => ['interfaces.read', 'traffic.interface.read'],
        'simple_queues' => ['queue.simple.read'],
        'hotspot_sessions' => ['hotspot.sessions.read'],
        'arp_entries' => ['arp.read'],
        'dhcp_leases' => ['dhcp.leases.read'],
    ];

    public function recordHealthEvidence(Router $router, array $payload, CarbonInterface $observedAt): RouterCapabilitySnapshot
    {
        $observed = ['system.read'];
        if (array_key_exists('ppp_active', $payload) && is_array($payload['ppp_active'])) {
            $observed[] = 'pppoe.sessions.read';
        }

        return $this->persist($router, $payload, $observed, 'health', $observedAt);
    }

    public function recordTrafficEvidence(Router $router, array $snapshot): RouterCapabilitySnapshot
    {
        $datasets = (array) data_get($snapshot, 'evidence.datasets', []);
        $observed = collect($datasets)
            ->flatMap(fn (string $dataset): array => self::DATASET_CAPABILITIES[$dataset] ?? [])
            ->unique()
            ->values()
            ->all();

        return $this->persist(
            $router,
            (array) ($snapshot['router'] ?? []),
            $observed,
            'traffic',
            $snapshot['collected_at'] ?? now(),
        );
    }

    public function prune(): int
    {
        return RouterCapabilitySnapshot::query()
            ->where('verified_at', '<', now()->subDays(max(1, (int) config('monitoring.capabilities.retention_days', 90))))
            ->delete();
    }

    private function persist(Router $router, array $payload, array $observed, string $source, CarbonInterface|string $verifiedAt): RouterCapabilitySnapshot
    {
        $latest = RouterCapabilitySnapshot::query()
            ->where('tenant_id', $router->tenant_id)
            ->where('router_id', $router->id)
            ->latest('verified_at')
            ->first();
        $version = $this->normalizeVersion($payload['version'] ?? null) ?? $latest?->routeros_version;
        $major = $version ? (int) explode('.', $version)[0] : null;

        $capabilities = $latest?->capabilities ?? [];
        foreach (self::READ_CAPABILITIES as $capability) {
            $capabilities[$capability] ??= 'unknown';
        }
        foreach ($observed as $capability) {
            if (in_array($capability, self::READ_CAPABILITIES, true)) {
                $capabilities[$capability] = 'supported';
            }
        }
        ksort($capabilities);

        $attributes = [
            'routeros_version' => $version,
            'routeros_major' => $major,
            'architecture' => $this->text($payload['architecture'] ?? null) ?? $latest?->architecture,
            'board' => $this->text($payload['board'] ?? $payload['board_name'] ?? null) ?? $latest?->board,
            'capabilities' => $capabilities,
        ];
        $checkpointDue = ! $latest || $latest->verified_at->lte(now()->subSeconds(max(1, (int) config('monitoring.capabilities.checkpoint_seconds', 86400))));
        $unchanged = $latest
            && $latest->only(array_keys($attributes)) === $attributes;
        if ($unchanged && ! $checkpointDue) {
            return $latest;
        }

        return RouterCapabilitySnapshot::create($attributes + [
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'source' => $source,
            'verified_at' => $verifiedAt,
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
