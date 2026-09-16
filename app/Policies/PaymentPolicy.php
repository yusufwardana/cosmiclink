<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function view(User $user, Payment $payment): bool
    {
        return $user->tenant_id === $payment->tenant_id;
    }

    public function create(User $user, Payment $payment): bool
    {
        return $this->view($user, $payment);
    }
}
