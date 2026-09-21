<?php

namespace App\Services\Network;

use App\Models\NetworkDiscoverySnapshot;
use App\Models\Router;

class RouterCompatibilityEvidenceService
{
    private const TARGET = 'routeros_v6_api';

    public function evaluate(Router $router): RouterCompatibilityDecision
    {
        $provider = (string) config('network.mutation_provider', 'fake');
        $executionMode = $provider === 'fake' ? 'simulation' : 'real';

        if ($provider !== 'fake') {
            return $this->decision(false, 'PROVIDER_UNSUPPORTED', $provider, $executionMode);
        }

        $snapshot = NetworkDiscoverySnapshot::query()
            ->where('tenant_id', $router->tenant_id)
            ->where('router_id', $router->id)
            ->where('status', 'success')
            ->orderByDesc('discovered_at')
            ->orderByDesc('id')
            ->first();

        if (! $snapshot) {
            return $this->decision(false, 'IDENTITY_MISSING', $provider, $executionMode);
        }

        $device = is_array($snapshot->snapshot['device'] ?? null) ? $snapshot->snapshot['device'] : [];
        $identity = is_string($device['name'] ?? null) ? trim($device['name']) : '';

        if ($identity === '') {
            return $this->decision(false, 'IDENTITY_MISSING', $provider, $executionMode, $snapshot->id);
        }

        if (! $snapshot->discovered_at) {
            return $this->decision(false, 'VERSION_MISSING', $provider, $executionMode, $snapshot->id, observedIdentity: $identity);
        }

        if ($snapshot->discovered_at->lt(now()->subSeconds((int) config('network.discovery_freshness_seconds', 86400)))) {
            return $this->decision(false, 'DISCOVERY_STALE', $provider, $executionMode, $snapshot->id, observedIdentity: $identity);
        }

        $rawVersion = $device['routeros_version'] ?? null;
        if ($rawVersion === null || trim((string) $rawVersion) === '') {
            return $this->decision(false, 'VERSION_MISSING', $provider, $executionMode, $snapshot->id, observedIdentity: $identity);
        }

        $rawVersion = trim((string) $rawVersion);
        if (! preg_match('/^(\d+\.\d+\.\d+)(?: \([A-Za-z0-9][A-Za-z0-9.-]*\))?$/D', $rawVersion, $matches)) {
            return $this->decision(false, 'VERSION_INVALID', $provider, $executionMode, $snapshot->id, rawVersion: $rawVersion, observedIdentity: $identity);
        }
        $version = $matches[1];

        if (! str_starts_with($version, '6.')) {
            return $this->decision(false, 'VERSION_UNSUPPORTED', $provider, $executionMode, $snapshot->id, $version, $rawVersion, $identity);
        }

        // Real-hardware authorization is never assumed from compatible v6
        // evidence: it requires an explicit, unrevoked, exactly scoped
        // acceptance whose recorded evidence still matches what was observed.
        $acceptance = app(RouterHardwareAcceptanceService::class)
            ->authorizes($router, self::TARGET, $version, $identity, $device['architecture'] ?? null);

        return $this->decision(true, 'COMPATIBLE', $provider, $executionMode, $snapshot->id, $version, $rawVersion, $identity, $acceptance, $acceptance);
    }

    private function decision(bool $compatible, string $reason, string $provider, string $executionMode, ?int $snapshotId = null, ?string $version = null, ?string $rawVersion = null, ?string $observedIdentity = null, bool $realProviderCompatible = false, bool $hardwareAccepted = false): RouterCompatibilityDecision
    {
        return new RouterCompatibilityDecision(
            compatible: $compatible,
            reason: $reason,
            target: self::TARGET,
            configuredProvider: $provider,
            executionMode: $executionMode,
            realProviderCompatible: $realProviderCompatible,
            architectureSupported: $provider === 'fake' || $provider === 'routeros',
            hardwareAccepted: $hardwareAccepted,
            snapshotId: $snapshotId,
            routerOsVersion: $version,
            rawRouterOsVersion: $rawVersion,
            observedIdentity: $observedIdentity,
        );
    }
}
