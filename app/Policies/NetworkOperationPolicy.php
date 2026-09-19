<?php

namespace App\Policies;

use App\Models\NetworkOperationLog;
use App\Models\User;

class NetworkOperationPolicy
{
    public function viewNetworkOperationLog(User $user, NetworkOperationLog $log): bool
    {
        return $user->tenant_id === $log->tenant_id && $user->isNetworkOperator();
    }
}
