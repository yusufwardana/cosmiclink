<?php

namespace App\Policies;

use App\Models\Router;
use App\Models\User;

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

    public function operate(User $user, Router $router): bool
    {
        return $this->view($user, $router);
    }
}
