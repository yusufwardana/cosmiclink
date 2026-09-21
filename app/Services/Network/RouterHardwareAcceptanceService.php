<?php

namespace App\Services\Network;

use App\Models\Router;
use App\Models\RouterHardwareAcceptance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Explicit, persisted real-hardware acceptance for one router scope.
 *
 * Acceptance is granted by a tenant network operator with an exact
 * confirmation phrase, is bound to the router's synced Agent reference and
 * installation, and records the compatibility evidence it was granted against.
 * It never widens the controlled gate by itself: the compatibility evidence
 * service consults this row, and any drift in the Agent scope, observed
 * hardware identity, or RouterOS version fails the acceptance closed. The
 * confirmation is stored as a digest only.
 */
class RouterHardwareAcceptanceService
{
    public const CONFIRMATION = 'CONFIRM_HARDWARE_ACCEPTANCE';

    public const TARGET = 'routeros_v6_api';

    public function __construct(
        private readonly RouterCompatibilityEvidenceService $compatibility,
    ) {}

    public function accept(Router $router, User $actor, string $confirmation, ?string $agentRef = null, ?string $installationId = null): RouterHardwareAcceptance
    {
        return DB::transaction(function () use ($router, $actor, $confirmation, $agentRef, $installationId): RouterHardwareAcceptance {
            $router = Router::query()->lockForUpdate()->findOrFail($router->id);
            $this->assertActor($router, $actor);
            if (! hash_equals(self::CONFIRMATION, $confirmation)) {
                throw new InvalidArgumentException('Hardware acceptance confirmation is invalid.');
            }

            $boundAgentRef = $agentRef ?? $router->observer_agent_ref;
            $boundInstallationId = $installationId ?? $router->observer_installation_id;
            if (! is_string($boundAgentRef) || $boundAgentRef === ''
                || ! is_string($boundInstallationId) || $boundInstallationId === ''
                || $boundAgentRef !== $router->observer_agent_ref
                || $boundInstallationId !== $router->observer_installation_id
                || $router->observer_credential_purpose !== 'OBSERVER'
                || $router->observer_credential_status !== 'ACTIVE') {
                throw new InvalidArgumentException('Hardware acceptance requires an exact synced Agent installation scope.');
            }

            $compatibility = $this->compatibility->evaluate($router);
            if (! $compatibility->compatible) {
                throw new InvalidArgumentException('Hardware acceptance requires current compatible RouterOS v6 evidence.');
            }

            $snapshot = $router->discoverySnapshots()->whereKey($compatibility->snapshotId)->first();
            $architecture = $snapshot?->snapshot['device']['architecture'] ?? null;
            if (! $this->validArchitecture($architecture)) {
                throw new InvalidArgumentException('Hardware acceptance requires valid observed architecture.');
            }

            // A new acceptance supersedes any previous active grant so at most
            // one explicit scope is ever authorizing for a router.
            RouterHardwareAcceptance::query()
                ->where('router_id', $router->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_by_user_id' => $actor->id]);

            return RouterHardwareAcceptance::create([
                'tenant_id' => $router->tenant_id,
                'router_id' => $router->id,
                'agent_ref' => $boundAgentRef,
                'installation_id' => $boundInstallationId,
                'target' => self::TARGET,
                'routeros_version' => (string) $compatibility->routerOsVersion,
                'observed_identity' => $compatibility->observedIdentity,
                'architecture' => is_string($architecture) ? mb_substr($architecture, 0, 64) : null,
                'discovery_snapshot_id' => $compatibility->snapshotId,
                'accepted_by_user_id' => $actor->id,
                'confirmation_digest' => hash('sha256', $confirmation),
                'accepted_at' => now(),
            ]);
        });
    }

    public function revoke(Router $router, User $actor): int
    {
        return DB::transaction(function () use ($router, $actor): int {
            $router = Router::query()->lockForUpdate()->findOrFail($router->id);
            $this->assertActor($router, $actor);

            return RouterHardwareAcceptance::query()
                ->where('router_id', $router->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_by_user_id' => $actor->id]);
        });
    }

    /**
     * Does an explicit, unrevoked acceptance still match this router's synced
     * Agent scope and the current compatibility evidence? Any drift fails
     * closed instead of being re-interpreted.
     */
    public function authorizes(Router $router, string $target, string $routerOsVersion, ?string $observedIdentity, mixed $architecture = null): bool
    {
        if (! $this->validArchitecture($architecture)) {
            return false;
        }
        $router->refresh();
        if ($router->observer_agent_ref === null || $router->observer_installation_id === null
            || $router->observer_credential_purpose !== 'OBSERVER'
            || $router->observer_credential_status !== 'ACTIVE') {
            return false;
        }

        return RouterHardwareAcceptance::query()
            ->where('tenant_id', $router->tenant_id)
            ->where('router_id', $router->id)
            ->where('agent_ref', $router->observer_agent_ref)
            ->where('installation_id', $router->observer_installation_id)
            ->where('target', $target)
            ->where('routeros_version', $routerOsVersion)
            ->where('observed_identity', $observedIdentity)
            ->where('architecture', $architecture)
            ->whereNull('revoked_at')
            ->exists();
    }

    private function validArchitecture(mixed $architecture): bool
    {
        return is_string($architecture) && preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $architecture) === 1;
    }

    private function assertActor(Router $router, User $actor): void
    {
        if ($actor->tenant_id !== $router->tenant_id || ! $actor->isNetworkOperator()) {
            throw new InvalidArgumentException('Hardware acceptance requires a same-tenant network operator.');
        }
    }
}
