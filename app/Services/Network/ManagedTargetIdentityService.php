<?php

namespace App\Services\Network;

use App\Models\DiscoveredNetworkResource;
use App\Models\ManagedTargetPreflightEvidence;
use App\Models\ManagedTargetSession;
use App\Models\NetworkAccount;
use App\Models\NetworkDiscoverySnapshot;
use App\Support\Network\RouterResourceIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Owns the RouterOS target identity, session, and preflight evidence context
 * that an adopted account needs before any controlled lifecycle action can even
 * be considered (Phase 6i Task 2.8 groundwork for Task 3).
 *
 * Invariants this service will not break:
 *  - It only ever persists safe projections: a reference, its canonical form, a
 *    sha256 fingerprint, a version string, and bounded session diagnostics.
 *    Passwords, service secrets, challenges, and plaintext confirmations have
 *    no column and no key here.
 *  - It never authorises anything. Callers receive a reason code and the failed
 *    precondition; the controlled gate stays default-deny regardless of what
 *    this service reports.
 *  - Identity evidence is derived from adopted discovery rows. When the recorded
 *    target no longer matches live adopted evidence, or the evidence aged past
 *    the discovery freshness window, resolution denies with a stale reason code
 *    instead of trusting the stored column.
 */
final class ManagedTargetIdentityService
{
    /** Providers whose snapshots are simulation artifacts, never a real target. */
    private const SIMULATION_PROVIDERS = ['fake', 'simulated'];

    /**
     * Project the adopted resource's RouterOS identity onto the account. Called
     * from adoption so the identity context is written in the same transaction
     * as the ADOPTED state transition. An unparseable reference clears the
     * context rather than storing something the lifecycle cannot address.
     */
    public function recordAdoptionContext(NetworkAccount $account, DiscoveredNetworkResource $resource): NetworkAccount
    {
        $identity = RouterResourceIdentity::parse($resource->external_ref);
        $snapshot = $resource->discovery_snapshot_id
            ? NetworkDiscoverySnapshot::query()->find($resource->discovery_snapshot_id)
            : null;

        if ($identity === null) {
            return $this->clearIdentityContext($account);
        }

        $account->forceFill([
            'router_identity_ref' => $identity->ref,
            'router_identity_type' => $identity->type,
            'router_identity_fingerprint' => $identity->fingerprint(),
            'routeros_version' => $this->versionFrom($snapshot),
            'router_identity_snapshot_id' => $snapshot?->id,
            'router_identity_resource_id' => $resource->id,
            'router_identity_evidence_at' => $resource->last_seen_at ?? $snapshot?->discovered_at ?? now(),
        ])->save();

        return $account->fresh();
    }

    /**
     * Drop the identity context. Called on unadopt so a previously adopted
     * reference can never be reused as though it were still evidence.
     */
    public function clearIdentityContext(NetworkAccount $account): NetworkAccount
    {
        $account->forceFill([
            'router_identity_ref' => null,
            'router_identity_type' => null,
            'router_identity_fingerprint' => null,
            'routeros_version' => null,
            'router_identity_snapshot_id' => null,
            'router_identity_resource_id' => null,
            'router_identity_evidence_at' => null,
        ])->save();

        return $account->fresh();
    }

    /**
     * Resolve the account's target identity context against live adopted
     * evidence. Read-only, and ordered so the most structural failure wins.
     */
    public function resolve(NetworkAccount $account): ManagedTargetIdentityResult
    {
        // Revocation outranks everything else: an account that no longer grants
        // management may not present a usable target, however current its
        // recorded identity evidence looks.
        if ($account->revoked_at !== null) {
            return ManagedTargetIdentityResult::denied(
                ControlledOperationReason::MANAGEMENT_REVOKED,
                ControlledOperationReason::PRE4_RESOURCE_ADOPTED,
                $account,
            );
        }

        $adopted = $this->adoptedResources($account);

        if ($adopted->isEmpty() || ! $this->hasCompleteContext($account)) {
            return ManagedTargetIdentityResult::denied(
                ControlledOperationReason::IDENTITY_MISSING,
                ControlledOperationReason::PRE8_TARGET_IDENTITY,
                $account,
            );
        }

        $identities = $adopted
            ->map(fn (DiscoveredNetworkResource $resource): ?RouterResourceIdentity => RouterResourceIdentity::parse($resource->external_ref))
            ->filter()
            ->unique(fn (RouterResourceIdentity $identity): string => $identity->fingerprint())
            ->values();

        if ($identities->count() > 1) {
            return ManagedTargetIdentityResult::denied(
                ControlledOperationReason::AMBIGUOUS_ACCOUNT_IDENTITY,
                ControlledOperationReason::PRE8_TARGET_IDENTITY,
                $account,
                sourceResource: $adopted->first(),
            );
        }

        if ($identities->isEmpty()) {
            return ManagedTargetIdentityResult::denied(
                ControlledOperationReason::IDENTITY_TYPE_UNSUPPORTED,
                ControlledOperationReason::PRE8_TARGET_IDENTITY,
                $account,
                sourceResource: $adopted->first(),
            );
        }

        /** @var RouterResourceIdentity $identity */
        $identity = $identities->first();
        $sourceResource = $adopted->first(
            fn (DiscoveredNetworkResource $resource): bool => RouterResourceIdentity::parse($resource->external_ref)?->fingerprint() === $identity->fingerprint()
        );
        $snapshot = $this->snapshotFor($account, $sourceResource);
        $context = [
            'account' => $account,
            'identity' => $identity,
            'sourceResource' => $sourceResource,
            'sourceSnapshot' => $snapshot,
            'evidenceAt' => $account->router_identity_evidence_at,
        ];

        if (! hash_equals((string) $account->router_identity_fingerprint, $identity->fingerprint())) {
            return $this->deniedWith(ControlledOperationReason::IDENTITY_STALE, $context);
        }

        // PRE8 also requires the target to resolve to exactly one account: two
        // accounts claiming the same RouterOS sentence means neither may be
        // addressed safely, so both report ambiguity.
        if ($this->identityClaimedByAnotherAccount($account, $identity)) {
            return $this->deniedWith(ControlledOperationReason::AMBIGUOUS_ACCOUNT_IDENTITY, $context, stale: false);
        }

        if ($this->evidenceIsStale($account)) {
            return $this->deniedWith(ControlledOperationReason::IDENTITY_STALE, $context);
        }

        if ($identity->isNameFallback() && ! (bool) config('network.controlled_operations.identity.allow_name_fallback', false)) {
            return $this->deniedWith(ControlledOperationReason::IDENTITY_TYPE_UNSUPPORTED, $context, stale: false);
        }

        return new ManagedTargetIdentityResult(
            resolved: true,
            reasonCode: ControlledOperationReason::APPROVED,
            account: $account,
            identity: $identity,
            sourceResource: $sourceResource,
            sourceSnapshot: $snapshot,
            evidenceAt: $account->router_identity_evidence_at,
            routerOsVersion: $account->routeros_version,
            realTargetEligible: ! $identity->isSimulated()
                && ! in_array((string) ($snapshot?->provider ?? 'unknown'), self::SIMULATION_PROVIDERS, true),
            activeSessions: $this->activeSessionProjections($account),
        );
    }

    /**
     * Store the bounded outcome of a preflight observation for one operation.
     * The row is keyed to the target identity fingerprint it was taken against
     * and to the account identity it was validated with, so Task 3 can detect a
     * target that moved between preflight and execution.
     */
    public function recordPreflightEvidence(
        NetworkAccount $account,
        string $operation,
        string $outcome,
        ?RouterResourceIdentity $identity = null,
        ?Carbon $observedAt = null,
        ?string $reasonCode = null,
    ): ManagedTargetPreflightEvidence {
        $observedAt ??= now();
        $sessionCount = count($this->activeSessionProjections($account));

        return ManagedTargetPreflightEvidence::create([
            'tenant_id' => $account->tenant_id,
            'router_id' => $account->router_id,
            'network_account_id' => $account->id,
            'discovery_snapshot_id' => $account->router_identity_snapshot_id,
            'operation' => $operation,
            'outcome' => $outcome,
            'reason_code' => $reasonCode,
            'target_identity_type' => $identity?->type,
            'target_identity_ref' => $identity?->ref,
            'target_identity_fingerprint' => $identity?->fingerprint(),
            'account_identity_fingerprint' => $account->router_identity_fingerprint,
            'routeros_version' => $account->routeros_version,
            'active_session_count' => $sessionCount,
            'evidence' => array_merge($identity?->projection() ?? [], [
                'operation' => $operation,
                'outcome' => $outcome,
                'reason_code' => $reasonCode,
                'routeros_version' => $account->routeros_version,
                'active_session_count' => $sessionCount,
                'observed_at' => $observedAt->toIso8601String(),
            ]),
            'observed_at' => $observedAt,
            'expires_at' => $observedAt->copy()->addSeconds($this->preflightTtlSeconds()),
        ]);
    }

    /**
     * Is there preflight evidence that is still valid for this account and
     * operation? A current row is still not authorisation on its own; these
     * reason codes are what Task 3 will evaluate in order.
     */
    public function preflightEvidenceIsCurrent(NetworkAccount $account, string $operation): ManagedTargetIdentityResult
    {
        $evidence = ManagedTargetPreflightEvidence::query()
            ->where('tenant_id', $account->tenant_id)
            ->where('network_account_id', $account->id)
            ->where('operation', $operation)
            ->latest('observed_at')
            ->first();

        if ($evidence === null) {
            return ManagedTargetIdentityResult::denied(
                ControlledOperationReason::PREFLIGHT_MISSING,
                ControlledOperationReason::PRE8_TARGET_IDENTITY,
                $account,
            );
        }

        if (! $evidence->isCurrent(now())) {
            return $this->preflightDenied(ControlledOperationReason::PREFLIGHT_STALE, $account, $evidence);
        }

        $accountFingerprint = (string) $account->router_identity_fingerprint;
        if ($accountFingerprint === ''
            || ! hash_equals($accountFingerprint, (string) $evidence->account_identity_fingerprint)
            || ! hash_equals($accountFingerprint, (string) $evidence->target_identity_fingerprint)) {
            return $this->preflightDenied(ControlledOperationReason::PRE_FLIGHT_IDENTITY_MISMATCH, $account, $evidence);
        }

        if ($evidence->outcome !== ManagedTargetPreflightEvidence::OUTCOME_READY) {
            return $this->preflightDenied(ControlledOperationReason::PREFLIGHT_NOT_READY, $account, $evidence);
        }

        return new ManagedTargetIdentityResult(
            resolved: true,
            reasonCode: ControlledOperationReason::APPROVED,
            account: $account,
            identity: RouterResourceIdentity::parse($evidence->target_identity_ref),
            sourceSnapshot: $this->snapshotFor($account),
            evidenceAt: $evidence->observed_at,
            routerOsVersion: $evidence->routeros_version,
            activeSessions: $this->activeSessionProjections($account),
            preflightEvidence: $evidence,
        );
    }

    /**
     * Project one router-wide `active_sessions` enumeration onto the accounts
     * this tenant has already adopted.
     *
     * A null payload means the provider did not enumerate sessions at all, so no
     * inference is made in either direction. An empty array means the router was
     * enumerated and no session was found, which supersedes previously ACTIVE
     * rows instead of deleting them, keeping the observation window auditable.
     *
     * @param  array<int, mixed>|null  $sessions
     */
    public function recordObservedSessions(NetworkDiscoverySnapshot $snapshot, ?array $sessions): int
    {
        if ($sessions === null) {
            return 0;
        }

        $observedByAccount = [];
        $recorded = 0;

        foreach ($sessions as $session) {
            if (! is_array($session)) {
                continue;
            }

            $identity = RouterResourceIdentity::parse($session['external_ref'] ?? null);
            $name = trim((string) ($session['name'] ?? ''));

            if ($identity === null || $name === '') {
                continue;
            }

            $account = NetworkAccount::query()
                ->where('tenant_id', $snapshot->tenant_id)
                ->where('router_id', $snapshot->router_id)
                ->whereRaw('LOWER(username) = ?', [mb_strtolower($name)])
                ->first();

            if ($account === null) {
                continue;
            }

            ManagedTargetSession::query()->updateOrCreate(
                [
                    'tenant_id' => $snapshot->tenant_id,
                    'network_account_id' => $account->id,
                    'session_ref' => $identity->ref,
                    'status' => ManagedTargetSession::STATE_ACTIVE,
                ],
                [
                    'router_id' => $snapshot->router_id,
                    'discovery_snapshot_id' => $snapshot->id,
                    'session_ref_type' => $identity->type,
                    'session_ref_fingerprint' => $identity->fingerprint(),
                    'session_name' => mb_substr($name, 0, 191),
                    'service' => $this->boundedText($session['service'] ?? null, 64),
                    'address' => $this->boundedText($session['address'] ?? null, 64),
                    'caller_id' => $this->boundedText($session['caller-id'] ?? $session['caller_id'] ?? null, 191),
                    'uptime_seconds' => $this->uptimeSeconds($session['uptime'] ?? $session['session-uptime'] ?? null),
                    'observed_at' => $snapshot->discovered_at,
                    'superseded_at' => null,
                ]
            );

            $observedByAccount[$account->id][] = $identity->ref;
            $recorded++;
        }

        ManagedTargetSession::query()
            ->where('tenant_id', $snapshot->tenant_id)
            ->where('router_id', $snapshot->router_id)
            ->where('status', ManagedTargetSession::STATE_ACTIVE)
            ->get()
            ->each(function (ManagedTargetSession $row) use ($observedByAccount, $snapshot): void {
                if (in_array($row->session_ref, $observedByAccount[$row->network_account_id] ?? [], true)) {
                    return;
                }

                $row->forceFill([
                    'status' => ManagedTargetSession::STATE_SUPERSEDED,
                    'superseded_at' => $snapshot->discovered_at ?? now(),
                ])->save();
            });

        return $recorded;
    }

    /**
     * @return Collection<int, DiscoveredNetworkResource>
     */
    private function adoptedResources(NetworkAccount $account): Collection
    {
        return DiscoveredNetworkResource::query()
            ->where('tenant_id', $account->tenant_id)
            ->where('router_id', $account->router_id)
            ->where('network_account_id', $account->id)
            ->where('resource_type', 'pppoe_account')
            ->where('management_state', 'ADOPTED')
            ->orderBy('id')
            ->get();
    }

    private function identityClaimedByAnotherAccount(NetworkAccount $account, RouterResourceIdentity $identity): bool
    {
        return NetworkAccount::query()
            ->where('tenant_id', $account->tenant_id)
            ->where('router_id', $account->router_id)
            ->whereKeyNot($account->id)
            ->where('router_identity_fingerprint', $identity->fingerprint())
            ->exists();
    }

    private function hasCompleteContext(NetworkAccount $account): bool
    {
        return filled($account->router_identity_ref)
            && filled($account->router_identity_type)
            && filled($account->router_identity_fingerprint)
            && $account->router_identity_evidence_at !== null;
    }

    private function snapshotFor(NetworkAccount $account, ?DiscoveredNetworkResource $resource = null): ?NetworkDiscoverySnapshot
    {
        $id = $resource?->discovery_snapshot_id ?? $account->router_identity_snapshot_id;

        return $id ? NetworkDiscoverySnapshot::query()->find($id) : null;
    }

    private function versionFrom(?NetworkDiscoverySnapshot $snapshot): ?string
    {
        return $this->boundedText($snapshot?->snapshot['device']['routeros_version'] ?? null, 32);
    }

    private function evidenceIsStale(NetworkAccount $account): bool
    {
        $evidenceAt = $account->router_identity_evidence_at;

        if ($evidenceAt === null) {
            return true;
        }

        $freshness = max(1, (int) config('network.discovery_freshness_seconds', 86400));

        return $evidenceAt->lt(now()->subSeconds($freshness));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activeSessionProjections(NetworkAccount $account): array
    {
        return ManagedTargetSession::query()
            ->where('tenant_id', $account->tenant_id)
            ->where('network_account_id', $account->id)
            ->where('status', ManagedTargetSession::STATE_ACTIVE)
            ->orderBy('observed_at')
            ->get()
            ->map(fn (ManagedTargetSession $session): array => [
                'session_ref' => $session->session_ref,
                'session_ref_type' => $session->session_ref_type,
                'session_ref_fingerprint' => $session->session_ref_fingerprint,
                'session_name' => $session->session_name,
                'service' => $session->service,
                'address' => $session->address,
                'uptime_seconds' => $session->uptime_seconds,
                'observed_at' => $session->observed_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @param  array{account: NetworkAccount, identity: RouterResourceIdentity, sourceResource: ?DiscoveredNetworkResource, sourceSnapshot: ?NetworkDiscoverySnapshot, evidenceAt: ?Carbon}  $context
     */
    private function deniedWith(string $reasonCode, array $context, bool $stale = true): ManagedTargetIdentityResult
    {
        return new ManagedTargetIdentityResult(
            resolved: false,
            reasonCode: $reasonCode,
            failedPrecondition: ControlledOperationReason::PRE8_TARGET_IDENTITY,
            account: $context['account'],
            identity: $context['identity'],
            sourceResource: $context['sourceResource'],
            sourceSnapshot: $context['sourceSnapshot'],
            evidenceAt: $context['evidenceAt'],
            routerOsVersion: $context['account']->routeros_version,
            stale: $stale,
            activeSessions: $this->activeSessionProjections($context['account']),
        );
    }

    private function preflightDenied(string $reasonCode, NetworkAccount $account, ManagedTargetPreflightEvidence $evidence): ManagedTargetIdentityResult
    {
        return new ManagedTargetIdentityResult(
            resolved: false,
            reasonCode: $reasonCode,
            failedPrecondition: ControlledOperationReason::PRE8_TARGET_IDENTITY,
            account: $account,
            identity: RouterResourceIdentity::parse($evidence->target_identity_ref),
            sourceSnapshot: $this->snapshotFor($account),
            evidenceAt: $evidence->observed_at,
            routerOsVersion: $evidence->routeros_version,
            stale: $reasonCode === ControlledOperationReason::PREFLIGHT_STALE,
            activeSessions: $this->activeSessionProjections($account),
            preflightEvidence: $evidence,
        );
    }

    private function boundedText(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    /**
     * RouterOS reports session uptime either as `1w2d3h4m5s` or as a
     * `d-HH:MM:SS` clock; both project to whole seconds.
     */
    private function uptimeSeconds(mixed $value): ?int
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^(?:(\d+)d-)?(\d{1,2}):(\d{2}):(\d{2})$/', $value, $clock) === 1) {
            return ((int) ($clock[1] ?: 0)) * 86400 + (int) $clock[2] * 3600 + (int) $clock[3] * 60 + (int) $clock[4];
        }

        if (preg_match_all('/(\d+)([wdhms])/', $value, $parts, PREG_SET_ORDER) === 0) {
            return null;
        }

        $units = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
        $seconds = 0;
        $seen = [];

        foreach ($parts as $part) {
            $unit = $part[2];

            if (isset($seen[$unit])) {
                return null;
            }

            $seen[$unit] = true;
            $seconds += (int) $part[1] * $units[$unit];
        }

        return $seconds;
    }

    private function preflightTtlSeconds(): int
    {
        return max(1, (int) config('network.controlled_operations.preflight_ttl_seconds', 300));
    }
}
