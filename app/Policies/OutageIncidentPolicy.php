<?php

namespace App\Policies;

use App\Models\OutageIncident;
use App\Models\User;

class OutageIncidentPolicy
{
    public function view(User $user, OutageIncident $incident): bool
    {
        return $user->tenant_id === $incident->tenant_id;
    }

    public function acknowledge(User $user, OutageIncident $incident): bool
    {
        return $this->view($user, $incident) && $incident->status === 'detected';
    }
}
