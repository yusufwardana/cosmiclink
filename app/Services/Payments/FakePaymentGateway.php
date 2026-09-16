<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\PaymentRequest;

class FakePaymentGateway implements PaymentGateway
{
    public function createPaymentRequest(Invoice $invoice): PaymentRequest
    {
        abort_unless($invoice->outstanding() > 0 && $invoice->status !== 'cancelled', 422, 'Invoice cannot create a payment request.');
        $existing = PaymentRequest::where('invoice_id', $invoice->id)->where('status', 'pending')->first();
        if ($existing) {
            return $existing;
        }

return PaymentRequest::create(['tenant_id' => $invoice->tenant_id, 'invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id, 'provider' => 'fake', 'provider_reference' => 'PAY-DEMO-'.str_pad((string) (PaymentRequest::count() + 1), 6, '0', STR_PAD_LEFT), 'amount' => $invoice->outstanding(), 'currency' => 'IDR', 'status' => 'pending', 'payment_url' => 'SIMULATED PAYMENT', 'qr_payload' => 'SIMULATED-PAYMENT', 'expires_at' => now()->addDay(), 'metadata' => ['simulation' => true]]);
    }

    public function simulateSuccess(PaymentRequest $request): array
    {
        return ['provider' => 'fake', 'provider_event_id' => 'EVT-DEMO-'.$request->id, 'provider_reference' => $request->provider_reference, 'amount' => $request->amount, 'currency' => $request->currency, 'event_type' => 'payment_succeeded'];
    }

    public function simulateFailure(PaymentRequest $request): array
    {
        return ['provider' => 'fake', 'provider_event_id' => 'EVT-FAIL-'.$request->id, 'provider_reference' => $request->provider_reference, 'amount' => $request->amount, 'currency' => $request->currency, 'event_type' => 'payment_failed'];
    }
}
