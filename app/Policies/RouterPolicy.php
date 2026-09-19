<?php

namespace App\Policies;

use App\Models\Router;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class RouterPolicy
{
    public function view(User $user, Router $router): bool
    {
        return $user->tenant_id === $router->tenant_id;
    }

    public function update(User $user, Router $router): bool
    {
        return $this->view($user, $router);
    }

    public function delete(User $user, Router $router): bool
    {
        return $this->view($user, $router);
    }

    /**
     * Device operation on this router (connection test, account mutation,
     * discovery, monitoring probe).
     *
     * Requires both the tenant boundary and the network-operator capability;
     * tenancy alone is data isolation, not authority to touch the device.
     */
    public function operate(User $user, Router $router): bool
    {
        return $this->view($user, $router) && Gate::forUser($user)->check('operate-network');
    }
}
