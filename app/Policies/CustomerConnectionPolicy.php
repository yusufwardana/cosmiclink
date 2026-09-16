<?php

namespace App\Policies;

use App\Models\CustomerConnection;
use App\Models\User;

class CustomerConnectionPolicy
{
    public function view(User $user, CustomerConnection $connection): bool
    {
        return $user->tenant_id === $connection->tenant_id;
    }

    public function update(User $user, CustomerConnection $connection): bool
    {
        return $this->view($user, $connection);
    }
}
