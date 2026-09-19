<?php

namespace App\Services\Network;

use App\Models\DiscoveredNetworkResource;
use App\Models\ManagedTargetPreflightEvidence;
use App\Models\NetworkAccount;
use App\Models\NetworkDiscoverySnapshot;
use App\Support\Network\RouterResourceIdentity;
use Illuminate\Support\Carbon;

/**
 * Outcome of resolving the RouterOS target identity/session/preflight context
 * for one managed network account.
 *
 * This is evidence, not authorisation: a resolved identity context only means
 * the controlled gate has something safe and current to check against. Task 3's
 * ordered PRE1-PRE12 evaluation decides whether an action may run.
 */
final readonly class ManagedTargetIdentityResult
{
    /**
     * @param  list<array<string, mixed>>  $activeSessions
     */
    public function __construct(
        public bool $resolved,
        public string $reasonCode,
        public ?string $failedPrecondition = null,
        public ?NetworkAccount $account = null,
        public ?RouterResourceIdentity $identity = null,
        public ?DiscoveredNetworkResource $sourceResource = null,
        public ?NetworkDiscoverySnapshot $sourceSnapshot = null,
        public ?Carbon $evidenceAt = null,
        public ?string $routerOsVersion = null,
        public bool $stale = false,
        public bool $realTargetEligible = false,
        public array $activeSessions = [],
        public ?ManagedTargetPreflightEvidence $preflightEvidence = null,
    ) {}

    public static function denied(
        string $reasonCode,
        string $failedPrecondition,
        ?NetworkAccount $account = null,
        bool $stale = false,
        ?DiscoveredNetworkResource $sourceResource = null,
        ?NetworkDiscoverySnapshot $sourceSnapshot = null,
        ?Carbon $evidenceAt = null,
    ): self {
        return new self(
            resolved: false,
            reasonCode: $reasonCode,
            failedPrecondition: $failedPrecondition,
            account: $account,
            sourceResource: $sourceResource,
            sourceSnapshot: $sourceSnapshot,
            evidenceAt: $evidenceAt,
            stale: $stale,
        );
    }

    public function activeSessionCount(): int
    {
        return count($this->activeSessions);
    }

    public function hasActiveSession(): bool
    {
        return $this->activeSessionCount() > 0;
    }

    /**
     * Safe projection for audit/evidence payloads: references and digests only,
     * never relationship models and never credential material.
     *
     * @return array<string, mixed>
     */
    public function projection(): array
    {
        return [
            'resolved' => $this->resolved,
            'reason_code' => $this->reasonCode,
            'failed_precondition' => $this->failedPrecondition,
            'network_account_id' => $this->account?->id,
            'identity_type' => $this->identity?->type,
            'identity_ref' => $this->identity?->ref,
            'identity_fingerprint' => $this->identity?->fingerprint(),
            'routeros_version' => $this->routerOsVersion,
            'evidence_at' => $this->evidenceAt?->toIso8601String(),
            'source_snapshot_id' => $this->sourceSnapshot?->id,
            'source_resource_id' => $this->sourceResource?->id,
            'stale' => $this->stale,
            'real_target_eligible' => $this->realTargetEligible,
            'active_session_count' => $this->activeSessionCount(),
            'preflight_evidence_id' => $this->preflightEvidence?->id,
        ];
    }
}
