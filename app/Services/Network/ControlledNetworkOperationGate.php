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

    public function check(User $user, NetworkAccount $account, string $operation): ControlledNetworkOperationDecision
    {
        if (! config('network.mutations_enabled', false)) {
            return ControlledNetworkOperationDecision::deny('MUTATIONS_DISABLED');
        }

        if ($user->tenant_id !== $account->tenant_id) {
            return ControlledNetworkOperationDecision::deny('TENANT_MISMATCH');
        }

        if (! $user->isNetworkOperator()) {
            return ControlledNetworkOperationDecision::deny('OPERATION_NOT_ALLOWED');
        }

        if (! in_array($operation, self::ALLOWED_OPERATIONS, true)) {
            return ControlledNetworkOperationDecision::deny('OPERATION_NOT_ALLOWED');
        }

        if ($account->management_state !== 'MANAGED') {
            return ControlledNetworkOperationDecision::deny('NOT_MANAGED');
        }

        // Reconciliation and fresh router-health evidence are intentionally not
        // available in Task 2. Missing safety evidence fails closed.
        return ControlledNetworkOperationDecision::deny('RECONCILIATION_NOT_MATCHED');
    }

    public function allowedOperations(): array
    {
        return self::ALLOWED_OPERATIONS;
    }
}
