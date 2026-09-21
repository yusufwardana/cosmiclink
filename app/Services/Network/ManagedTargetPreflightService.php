<?php

namespace App\Services\Network;

use App\Models\HealthObservation;
use App\Models\ManagedTargetPreflightEvidence;
use App\Models\NetworkAccount;
use App\Models\User;
use App\Services\Monitoring\MonitoringService;
use App\Support\Network\RouterResourceIdentity;
use InvalidArgumentException;

/**
 * Generates fresh, operation-specific preflight evidence for one managed
 * target immediately before a controlled mutation is considered.
 *
 * The service refreshes router health through the existing monitoring path,
 * takes a fresh read-only discovery snapshot, proves that exactly one hardware
 * account matches the account's adopted target identity and username, reads
 * the current hardware disabled state, and records a bounded READY evidence
 * row via the existing identity service. It never authorises anything by
 * itself, never targets more than the exact adopted identity, and fails closed
 * on ambiguity, missing state, or any identity drift.
 */
class ManagedTargetPreflightService
{
    public const OPERATIONS = ['ENABLE_PPPOE', 'DISABLE_PPPOE'];

    public function __construct(
        private readonly ManagedTargetIdentityService $targetIdentity,
    ) {}

    public function prepare(NetworkAccount $account, string $operation, User $actor): ManagedTargetPreflightResult
    {
        $account = $account->fresh();
        if ($actor->tenant_id !== $account->tenant_id || ! $actor->isNetworkOperator()) {
            throw new InvalidArgumentException('Preflight requires a same-tenant network operator.');
        }
        if (! in_array($operation, self::OPERATIONS, true)) {
            throw new InvalidArgumentException('Preflight is operation-specific and only covers controlled enable/disable.');
        }
        if ($account->management_state !== 'MANAGED') {
            return $this->unresolved($operation, ControlledOperationReason::NOT_MANAGED, $account);
        }

        $router = $account->router()->firstOrFail();

        // Health must be fresh at decision time: observe through the existing
        // read-only monitoring path, then let the existing freshness query be
        // the sole judge. The observation itself never authorises a write.
        $health = app(MonitoringService::class)->observeRouter($router, $actor);

        $snapshot = app(NetworkDiscoveryService::class)->discover($router, $actor);
        if ($snapshot->status !== 'success' || ! is_array($snapshot->snapshot)) {
            return $this->unresolved($operation, ControlledOperationReason::DISCOVERY_STALE, $account, $health);
        }

        $identity = $this->targetIdentity->resolve($account);
        if (! $identity->resolved || ! $identity->identity instanceof RouterResourceIdentity) {
            return $this->unresolved($operation, $identity->reasonCode ?? ControlledOperationReason::PRE8_TARGET_IDENTITY, $account, $health);
        }

        $matches = [];
        foreach (($snapshot->snapshot['accounts'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (! $identity->identity->matches($row['external_ref'] ?? null)) {
                continue;
            }
            $matches[] = $row;
        }

        if (count($matches) === 0) {
            return $this->unresolved($operation, ControlledOperationReason::TARGET_NOT_FOUND, $account, $health);
        }
        if (count($matches) > 1) {
            return $this->unresolved($operation, ControlledOperationReason::AMBIGUOUS_ACCOUNT_IDENTITY, $account, $health);
        }

        $row = $matches[0];
        $username = mb_strtolower(trim((string) ($row['username'] ?? '')));
        if ($username === '' || $username !== mb_strtolower(trim((string) $account->username))) {
            return $this->unresolved($operation, ControlledOperationReason::IDENTITY_MISMATCH, $account, $health);
        }

        $hasDisabled = array_key_exists('disabled', $row);
        $hasEnabled = array_key_exists('enabled', $row);
        $disabled = $hasDisabled ? $this->hardwareBoolean($row['disabled']) : null;
        $enabled = $hasEnabled ? $this->hardwareBoolean($row['enabled']) : null;
        if ((! $hasDisabled && ! $hasEnabled)
            || ($hasDisabled && $disabled === null)
            || ($hasEnabled && $enabled === null)
            || ($hasDisabled && $hasEnabled && $disabled === $enabled)) {
            return $this->unresolved($operation, ControlledOperationReason::PRE8_TARGET_IDENTITY, $account, $health);
        }
        $disabled = $hasDisabled ? $disabled : ! $enabled;

        $evidence = $this->targetIdentity->recordPreflightEvidence(
            $account,
            $operation,
            ManagedTargetPreflightEvidence::OUTCOME_READY,
            $identity->identity,
            now(),
            null,
            [
                'hardware_disabled' => $disabled,
                'observed_username' => $row['username'],
                'discovery_snapshot_id' => $snapshot->id,
                'health_observed_at' => $health?->observed_at?->toIso8601String(),
                'health_state' => $health?->health_state,
            ],
        );

        return new ManagedTargetPreflightResult(true, null, $evidence, $disabled, $health);
    }

    private function hardwareBoolean(mixed $value): ?bool
    {
        return match ($value) {
            true, 1, '1', 'true', 'yes' => true,
            false, 0, '0', 'false', 'no' => false,
            default => null,
        };
    }

    private function unresolved(string $operation, string $reasonCode, NetworkAccount $account, ?HealthObservation $health = null): ManagedTargetPreflightResult
    {
        $this->targetIdentity->recordPreflightEvidence(
            $account,
            $operation,
            ManagedTargetPreflightEvidence::OUTCOME_DENIED,
            null,
            now(),
            $reasonCode,
        );

        return new ManagedTargetPreflightResult(false, $reasonCode, null, null, $health);
    }
}
