<?php

namespace App\Services\Network;

use App\Models\NetworkAccount;
use App\Models\User;

class ControlledNetworkOperationGate
{
    private const ALLOWED_OPERATIONS = [
        'ENABLE_PPPOE',
        'DISABLE_PPPOE',
        'DISCONNECT_SESSION',
    ];

    public function __construct(
        private readonly ManagedTargetIdentityService $targetIdentity,
    ) {}

    public function check(User $user, NetworkAccount $account, string $operation): ControlledNetworkOperationDecision
    {
        if (! config('network.mutations_enabled', false)) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::MUTATIONS_DISABLED);
        }

        if ($user->tenant_id !== $account->tenant_id) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::TENANT_MISMATCH);
        }

        if (! $user->isNetworkOperator()) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::OPERATION_NOT_ALLOWED);
        }

        if (! in_array($operation, self::ALLOWED_OPERATIONS, true)) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::OPERATION_NOT_ALLOWED);
        }

        if ($account->management_state !== 'MANAGED') {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::NOT_MANAGED);
        }
        if ($account->management_scope !== ManagedAccountLifecycleService::MANAGEMENT_SCOPE) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::SCOPE_NOT_CONFIRMED);
        }

        // Phase 6i Task 2.8: a managed account's RouterOS target identity,
        // session, and preflight context is evaluated and reported as evidence
        // on the decision. It is attributed to PRE8 (the identity precondition)
        // but does not pre-empt PRE7, so the ordered evaluation Task 3 adds sees
        // the same first-unsatisfied precondition the plan defines. Evidence
        // explains the deny; it never grants.
        $identity = $this->targetIdentity->resolve($account);

        // Reconciliation, compatibility, health, and concurrency evidence are
        // intentionally not evaluated here yet. Missing safety evidence fails
        // closed, so the gate stays default-deny until Task 3 adds the ordered
        // PRE1-PRE12 evaluation.
        return ControlledNetworkOperationDecision::deny(
            ControlledOperationReason::RECONCILIATION_NOT_MATCHED,
            ControlledOperationReason::PRE7_RECONCILIATION,
        )->withEvidence(['identity' => $identity->projection()]);
    }

    public function allowedOperations(): array
    {
        return self::ALLOWED_OPERATIONS;
    }
}
