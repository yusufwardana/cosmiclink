<?php

namespace App\Services\Network;

use App\Models\CustomerConnection;
use App\Models\DiscoveredNetworkResource;
use App\Models\ManagedTargetAction;
use App\Models\NetworkAccount;
use App\Models\NetworkAccountTransition;
use App\Models\NetworkDiscoverySnapshot;
use App\Models\ReconciliationEvidence;
use App\Models\Router;
use App\Models\User;
use App\Support\Network\RouterResourceIdentity;
use Illuminate\Support\Facades\DB;

final class ManagedAccountLifecycleService
{
    public const LIFECYCLE_OPERATION = 'MANAGE_ACCOUNT';

    public const CONFIRMATION = 'CONFIRM_MANAGED_SCOPE';

    /** @var array{operations: list<string>, version: int} */
    public const MANAGEMENT_SCOPE = [
        'version' => 1,
        'operations' => ['ENABLE_PPPOE', 'DISABLE_PPPOE', 'DISCONNECT_SESSION'],
    ];

    public function __construct(
        private readonly ManagedTargetIdentityService $targetIdentity,
        private readonly RouterCompatibilityEvidenceService $compatibility,
        private readonly RouterHealthSafetyQuery $health,
        private readonly NetworkOperationSafetyQuery $operationSafety,
    ) {}

    public function manage(NetworkAccount $account, User $actor, string $confirmation): ControlledNetworkOperationDecision
    {
        return DB::transaction(function () use ($account, $actor, $confirmation): ControlledNetworkOperationDecision {
            [$router, $resource, $connection, $lockedAccount] = $this->lockBoundary($account);
            $identity = null;

            $failure = $this->firstFailure(
                $actor,
                $router,
                $resource,
                $connection,
                $lockedAccount,
                $confirmation,
                $identity,
            );

            if ($failure !== null) {
                $this->recordAction($lockedAccount, $actor, $failure, $identity, $confirmation);

                return $failure;
            }

            $lockedAccount->forceFill([
                'management_state' => NetworkAccountTransition::STATE_MANAGED,
                'management_scope' => self::MANAGEMENT_SCOPE,
                'managed_at' => now(),
                'managed_by_user_id' => $actor->id,
                'revoked_at' => null,
                'revoked_by_user_id' => null,
            ])->save();

            NetworkAccountTransition::recordLocked(
                account: $lockedAccount,
                fromState: NetworkAccountTransition::STATE_ADOPTED,
                toState: NetworkAccountTransition::STATE_MANAGED,
                user: $actor,
                reasonCode: ControlledOperationReason::APPROVED,
                scope: self::MANAGEMENT_SCOPE,
            );
            $this->recordAction($lockedAccount, $actor, ControlledNetworkOperationDecision::allow(), $identity, $confirmation);

            return ControlledNetworkOperationDecision::allow();
        });
    }

    public function revoke(NetworkAccount $account, User $actor): ControlledNetworkOperationDecision
    {
        return DB::transaction(function () use ($account, $actor): ControlledNetworkOperationDecision {
            [$router, $resource, $connection, $lockedAccount] = $this->lockBoundary($account);

            if ($actor->tenant_id !== $lockedAccount->tenant_id || ! $actor->isNetworkOperator()) {
                $decision = ControlledNetworkOperationDecision::deny(ControlledOperationReason::OPERATION_NOT_ALLOWED, 'PRE1');
                $this->recordAction($lockedAccount, $actor, $decision);

                return $decision;
            }

            if ($lockedAccount->management_state !== NetworkAccountTransition::STATE_MANAGED) {
                $decision = ControlledNetworkOperationDecision::deny(ControlledOperationReason::NOT_MANAGED, 'PRE12');
                $this->recordAction($lockedAccount, $actor, $decision);

                return $decision;
            }

            $lockedAccount->forceFill([
                'management_state' => NetworkAccountTransition::STATE_ADOPTED,
                'management_scope' => null,
                'revoked_at' => now(),
                'revoked_by_user_id' => $actor->id,
            ])->save();

            NetworkAccountTransition::recordLocked(
                account: $lockedAccount,
                fromState: NetworkAccountTransition::STATE_MANAGED,
                toState: NetworkAccountTransition::STATE_ADOPTED,
                user: $actor,
                reasonCode: ControlledOperationReason::MANAGEMENT_REVOKED,
                scope: self::MANAGEMENT_SCOPE,
            );
            $this->recordAction($lockedAccount, $actor, ControlledNetworkOperationDecision::allow());

            return ControlledNetworkOperationDecision::allow();
        });
    }

    /** @return array{0: Router, 1: ?DiscoveredNetworkResource, 2: ?CustomerConnection, 3: NetworkAccount} */
    private function lockBoundary(NetworkAccount $account): array
    {
        $router = Router::query()->lockForUpdate()->findOrFail($account->router_id);
        $resource = DiscoveredNetworkResource::query()
            ->where('network_account_id', $account->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
        $connection = CustomerConnection::query()
            ->where('network_account_id', $account->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
        $lockedAccount = NetworkAccount::query()->lockForUpdate()->findOrFail($account->id);

        return [$router, $resource, $connection, $lockedAccount];
    }

    private function firstFailure(
        User $actor,
        Router $router,
        ?DiscoveredNetworkResource $resource,
        ?CustomerConnection $connection,
        NetworkAccount $account,
        string $confirmation,
        ?RouterResourceIdentity &$identity,
    ): ?ControlledNetworkOperationDecision {
        if ($actor->tenant_id !== $account->tenant_id || ! $actor->isNetworkOperator()) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::OPERATION_NOT_ALLOWED, 'PRE1');
        }
        if ($resource?->tenant_id !== $account->tenant_id || ($connection !== null && $connection->tenant_id !== $account->tenant_id)) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::TENANT_MISMATCH, 'PRE2');
        }
        if ($connection?->network_account_id !== $account->id) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::RELATIONSHIP_MISMATCH, 'PRE3');
        }
        if ($resource?->router_id !== $router->id || $connection?->router_id !== $router->id || $account->router_id !== $router->id) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::RELATIONSHIP_MISMATCH, 'PRE4');
        }
        if ($account->management_state !== NetworkAccountTransition::STATE_ADOPTED
            || $resource?->management_state !== NetworkAccountTransition::STATE_ADOPTED) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::TARGET_NOT_FOUND, 'PRE5');
        }
        $snapshot = NetworkDiscoverySnapshot::query()
            ->where('tenant_id', $router->tenant_id)
            ->where('router_id', $router->id)
            ->where('status', 'success')
            ->orderByDesc('discovered_at')
            ->orderByDesc('id')
            ->first();
        if ($snapshot === null || $resource->discovery_snapshot_id !== $snapshot->id || ! $resource->last_seen_at?->gte(now()->subSeconds((int) config('network.discovery_freshness_seconds', 86400)))) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::DISCOVERY_STALE, 'PRE6');
        }
        $evidence = ReconciliationEvidence::query()->where('adopted_resource_id', $resource->id)->latest('reconciled_at')->first();
        if ($evidence === null || ! $evidence->isCurrentlyApplicable()) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::RECONCILIATION_NOT_MATCHED, 'PRE7');
        }
        $identityResult = $this->targetIdentity->resolve($account);
        if (! $identityResult->resolved) {
            return ControlledNetworkOperationDecision::deny($identityResult->reasonCode, 'PRE8');
        }
        $identity = $identityResult->identity;
        $compatibility = $this->compatibility->evaluate($router);
        if (! $compatibility->compatible) {
            return ControlledNetworkOperationDecision::deny($compatibility->reason, 'PRE9');
        }
        if (! $this->health->allows($router)) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::ROUTER_HEALTH_UNKNOWN, 'PRE10');
        }
        if ($this->operationSafety->hasUnresolvedState($account)) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::CONCURRENT_OPERATION, 'PRE11');
        }
        if ($confirmation !== self::CONFIRMATION) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::SCOPE_NOT_CONFIRMED, 'PRE12');
        }

        return null;
    }

    private function recordAction(NetworkAccount $account, User $actor, ControlledNetworkOperationDecision $decision, ?RouterResourceIdentity $identity = null, ?string $confirmation = null): void
    {
        ManagedTargetAction::recordAttempt(
            account: $account,
            user: $actor,
            operation: self::LIFECYCLE_OPERATION,
            allowed: $decision->allowed,
            reasonCode: $decision->allowed ? ControlledOperationReason::APPROVED : $decision->errorCode,
            failedPrecondition: $decision->failedPrecondition,
            identity: $identity,
            confirmation: $confirmation,
        );
    }
}
