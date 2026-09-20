<?php

namespace Tests\Feature;

use App\Models\NetworkAccount;
use App\Models\NetworkDiscoverySnapshot;
use App\Models\Router;
use App\Services\Network\NetworkDiscoveryClient;
use App\Services\Network\RouterCompatibilityEvidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Phase6ITask26RouterCompatibilityEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_supported_routeros_v6_evidence_is_compatible_but_fake_does_not_prove_real_provider_compatibility(): void
    {
        $router = $this->routerWithSnapshot('CORE-01', '6.49.13');
        config(['network.mutation_provider' => 'fake']);

        $decision = app(RouterCompatibilityEvidenceService::class)->evaluate($router);

        $this->assertTrue($decision->compatible);
        $this->assertSame('COMPATIBLE', $decision->reason);
        $this->assertSame('routeros_v6_api', $decision->target);
        $this->assertSame('fake', $decision->configuredProvider);
        $this->assertSame('simulation', $decision->executionMode);
        $this->assertFalse($decision->realProviderCompatible);
        $this->assertFalse($decision->canAuthorizeRealMutation());
    }

    public function test_missing_router_identity_fails_closed(): void
    {
        $router = $this->routerWithSnapshot(null, '6.49.13');

        $this->assertReason('IDENTITY_MISSING', $router);
    }

    public function test_observed_hardware_identity_is_independent_from_operator_display_name(): void
    {
        $router = $this->routerWithSnapshot('OTHER-ROUTER', '6.49.13');

        $decision = app(RouterCompatibilityEvidenceService::class)->evaluate($router);

        $this->assertTrue($decision->compatible);
        $this->assertSame('COMPATIBLE', $decision->reason);
        $this->assertSame('OTHER-ROUTER', $decision->observedIdentity);
        $this->assertSame('CORE-01', $router->name);
    }

    public function test_routeros_channel_suffix_is_normalized_without_losing_raw_evidence(): void
    {
        $router = $this->routerWithSnapshot('TP-Link', '6.49.13 (long-term)');

        $decision = app(RouterCompatibilityEvidenceService::class)->evaluate($router);

        $this->assertTrue($decision->compatible);
        $this->assertSame('COMPATIBLE', $decision->reason);
        $this->assertSame('TP-Link', $decision->observedIdentity);
        $this->assertSame('6.49.13 (long-term)', $decision->rawRouterOsVersion);
        $this->assertSame('6.49.13', $decision->routerOsVersion);
        $this->assertTrue($decision->architectureSupported);
    }

    public function test_missing_version_fails_closed(): void
    {
        $router = $this->routerWithSnapshot('CORE-01', null);

        $this->assertReason('VERSION_MISSING', $router);
    }

    public function test_malformed_version_fails_closed(): void
    {
        foreach (['six.forty-nine', '6.49', '6.49.13 long-term', '6.49.13 (long term!)', '6.49.13 (long-term) trailing'] as $version) {
            $this->assertReason('VERSION_INVALID', $this->routerWithSnapshot('CORE-01', $version));
        }
    }

    public function test_version_boundaries_are_routeros_v6_only(): void
    {
        $this->assertReason('VERSION_UNSUPPORTED', $this->routerWithSnapshot('CORE-01', '5.26.3'));
        $this->assertReason('COMPATIBLE', $this->routerWithSnapshot('CORE-01', '6.0.0'));
        $this->assertReason('COMPATIBLE', $this->routerWithSnapshot('CORE-01', '6.49.13'));
        $this->assertReason('VERSION_UNSUPPORTED', $this->routerWithSnapshot('CORE-01', '7.0.0'));
    }

    public function test_unsupported_provider_fails_closed_without_fake_fallback(): void
    {
        $router = $this->routerWithSnapshot('CORE-01', '6.49.13');
        config(['network.mutation_provider' => 'unknown-provider']);

        $decision = app(RouterCompatibilityEvidenceService::class)->evaluate($router);

        $this->assertFalse($decision->compatible);
        $this->assertSame('PROVIDER_UNSUPPORTED', $decision->reason);
        $this->assertSame('unknown-provider', $decision->configuredProvider);
        $this->assertNotSame('fake', $decision->configuredProvider);
    }

    public function test_routeros_provider_is_not_claimed_compatible_before_real_provider_exists(): void
    {
        $router = $this->routerWithSnapshot('CORE-01', '6.49.13');
        config(['network.mutation_provider' => 'routeros']);

        $decision = app(RouterCompatibilityEvidenceService::class)->evaluate($router);

        $this->assertFalse($decision->compatible);
        $this->assertSame('PROVIDER_UNSUPPORTED', $decision->reason);
        $this->assertFalse($decision->realProviderCompatible);
    }

    public function test_stale_discovery_evidence_fails_closed_using_canonical_freshness(): void
    {
        config(['network.discovery_freshness_seconds' => 60]);
        $router = $this->routerWithSnapshot('CORE-01', '6.49.13', now()->subSeconds(61));

        $this->assertReason('DISCOVERY_STALE', $router);
    }

    public function test_newer_successful_discovery_supersedes_older_compatible_evidence(): void
    {
        $router = Router::factory()->create(['name' => 'CORE-01']);
        $this->snapshot($router, '6.49.13', now()->subMinute());
        $this->snapshot($router, '7.0.0', now());

        $this->assertReason('VERSION_UNSUPPORTED', $router);
    }

    public function test_tenant_or_router_attribution_mismatch_fails_closed(): void
    {
        $router = Router::factory()->create(['name' => 'CORE-01']);
        $otherTenantRouter = Router::factory()->create(['name' => 'CORE-01']);
        $this->snapshot($otherTenantRouter, '6.49.13', now());

        $this->assertReason('IDENTITY_MISSING', $router);
    }

    public function test_evaluation_does_not_call_discovery_or_change_management_state(): void
    {
        $router = $this->routerWithSnapshot('CORE-01', '6.49.13');
        $account = NetworkAccount::create([
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'username' => 'compatibility-test',
            'profile' => 'HOME-20M',
            'status' => 'active',
            'management_state' => 'ADOPTED',
        ]);
        app()->bind(NetworkDiscoveryClient::class, fn () => throw new \RuntimeException('network call forbidden'));

        $decision = app(RouterCompatibilityEvidenceService::class)->evaluate($router);

        $this->assertTrue($decision->compatible);
        $this->assertSame('ADOPTED', $account->fresh()->management_state);
    }

    private function assertReason(string $reason, Router $router): void
    {
        $decision = app(RouterCompatibilityEvidenceService::class)->evaluate($router);

        $this->assertSame($reason, $decision->reason);
        $this->assertSame($reason === 'COMPATIBLE', $decision->compatible);
    }

    private function routerWithSnapshot(?string $identity, ?string $version, ?Carbon $discoveredAt = null): Router
    {
        $router = Router::factory()->create(['name' => 'CORE-01']);
        $this->snapshot($router, $version, $discoveredAt ?? now(), $identity);

        return $router;
    }

    private function snapshot(Router $router, ?string $version, Carbon $discoveredAt, ?string $identity = 'CORE-01'): NetworkDiscoverySnapshot
    {
        $device = [];
        if ($identity !== null) {
            $device['name'] = $identity;
        }
        if ($version !== null) {
            $device['routeros_version'] = $version;
        }

        return NetworkDiscoverySnapshot::create([
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'provider' => 'fake',
            'status' => 'success',
            'discovered_at' => $discoveredAt,
            'summary' => [],
            'snapshot' => ['device' => $device],
        ]);
    }
}
