<?php

namespace App\Actions;

use App\Models\Invoice;
use App\Models\MessageLog;
use App\Models\User;
use Illuminate\Database\QueryException;

class SendPaymentReminder
{
    public function __construct(private readonly SendCustomerMessage $messenger) {}

    public function handle(Invoice $invoice, User $user): ?MessageLog
    {
        abort_unless($invoice->tenant_id === $user->tenant_id, 403);

        if (in_array($invoice->status, ['paid', 'cancelled'], true) || $invoice->outstanding() <= 0) {
            return null;
        }

        $invoice->loadMissing('customer');
        abort_unless($invoice->customer && $invoice->customer->tenant_id === $user->tenant_id, 403);

        $idempotencyKey = implode(':', [$invoice->tenant_id, $invoice->id, 'payment_reminder']);
        $existing = MessageLog::where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return $existing;
        }

        try {
            $message = $this->messenger->handle($invoice->customer, 'payment_reminder', [
                'customer_name' => $invoice->customer->name,
                'invoice_number' => $invoice->invoice_number,
                'period' => $invoice->billing_period_start?->format('F Y'),
                'outstanding' => $invoice->outstanding(),
                'due_date' => $invoice->due_date?->format('Y-m-d'),
            ], $user, $invoice, $invoice->customer_connection_id ? $invoice->connection : null, $idempotencyKey);

            return $message;
        } catch (QueryException $exception) {
            if (! str_contains($exception->getMessage(), 'idempotency_key')) {
                throw $exception;
            }

            return MessageLog::where('idempotency_key', $idempotencyKey)->firstOrFail();
        }
    }
}
