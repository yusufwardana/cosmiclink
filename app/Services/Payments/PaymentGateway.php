<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\PaymentRequest;

interface PaymentGateway
{
    public function createPaymentRequest(Invoice $invoice): PaymentRequest;

    public function simulateSuccess(PaymentRequest $request): array;

    public function simulateFailure(PaymentRequest $request): array;
}
