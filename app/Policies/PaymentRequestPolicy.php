<?php

namespace App\Policies;

use App\Models\PaymentRequest;
use App\Models\User;

class PaymentRequestPolicy
{
    public function view(User $user, PaymentRequest $request): bool
    {
        return $user->tenant_id === $request->tenant_id;
    }
}
