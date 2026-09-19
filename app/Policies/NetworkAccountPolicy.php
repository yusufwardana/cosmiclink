<?php

namespace App\Policies;

use App\Models\NetworkAccount;
use App\Models\User;

class NetworkAccountPolicy
{
    public function manageNetworkAccount(User $user, NetworkAccount $account): bool
    {
        return $this->authorized($user, $account);
    }

    public function executeNetworkOperation(User $user, NetworkAccount $account): bool
    {
        return $this->authorized($user, $account);
    }

    private function authorized(User $user, NetworkAccount $account): bool
    {
        return $user->tenant_id === $account->tenant_id && $user->isNetworkOperator();
    }
}
