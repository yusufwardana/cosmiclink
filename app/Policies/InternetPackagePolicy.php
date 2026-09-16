<?php

namespace App\Policies;

use App\Models\InternetPackage;
use App\Models\User;

class InternetPackagePolicy
{
    public function view(User $user, InternetPackage $package): bool
    {
        return $user->tenant_id === $package->tenant_id;
    }

    public function update(User $user, InternetPackage $package): bool
    {
        return $this->view($user, $package);
    }
}
