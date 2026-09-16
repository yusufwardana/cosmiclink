<?php

namespace App\Actions;

use App\Models\PaymentProviderEvent;
use App\Models\PaymentRequest;
use App\Models\User;

class ProcessPaymentProviderEvent
{
    public function __construct(private readonly RecordPayment $record) {}

    public function handle(array $payload, User $user): PaymentProviderEvent
    {
        $request = PaymentRequest::where('provider', $payload['provider'] ?? '')->where('provider_reference', $payload['provider_reference'] ?? '')->first();
        if (! $request) {
            throw new \InvalidArgumentException('Unknown payment reference.');
        } abort_unless($request->tenant_id === $user->tenant_id, 403);
        if ((int) ($payload['amount'] ?? 0) !== $request->amount || ($payload['currency'] ?? null) !== $request->currency) {
            throw new \InvalidArgumentException('Payment event amount mismatch.');
        } $existing = PaymentProviderEvent::where('provider_event_id', $payload['provider_event_id'] ?? '')->first();
        if ($existing) {
            return $existing;
        } $event = PaymentProviderEvent::create(['tenant_id' => $request->tenant_id, 'payment_request_id' => $request->id, 'invoice_id' => $request->invoice_id, 'provider' => $request->provider, 'provider_event_id' => $payload['provider_event_id'], 'event_type' => $payload['event_type'], 'status' => 'received', 'received_at' => now(), 'sanitized_payload' => ['provider' => $payload['provider'], 'provider_reference' => $payload['provider_reference'], 'amount' => (int) $payload['amount'], 'currency' => $payload['currency'], 'event_type' => $payload['event_type']]]);
        if ($payload['event_type'] !== 'payment_succeeded') {
            $event->update(['status' => 'failed', 'processed_at' => now(), 'failure_code' => 'PAYMENT_FAILED']);

            return $event;
        } if ($request->invoice->status !== 'paid') {
            $this->record->handle($request->invoice, $request->amount, 'manual', $request->provider_reference, $user);
        } $request->update(['status' => 'paid', 'paid_at' => now()]);
        $event->update(['status' => 'processed', 'processed_at' => now()]);

        return $event;
    }
}
