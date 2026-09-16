<?php

namespace App\Actions;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RecordPayment
{
    public function __construct(private readonly ReactivateCustomerConnection $reactivate) {}

    public function handle(Invoice $invoice, int $amount, string $method, string $reference, User $user): Payment
    {
        abort_unless($invoice->tenant_id === $user->tenant_id, 403);
        if ($invoice->status === 'cancelled' || $invoice->status === 'paid') {
            throw new \InvalidArgumentException('Invoice cannot accept payment.');
        } if ($amount <= 0 || $amount > $invoice->outstanding()) {
            throw new \InvalidArgumentException('Payment exceeds outstanding balance.');
        } if (Payment::where('payment_reference', $reference)->exists()) {
            throw new \InvalidArgumentException('Payment reference already exists.');
        }
        $payment = DB::transaction(function () use ($invoice, $amount, $method, $reference, $user) {
            $payment = Payment::create(['tenant_id' => $invoice->tenant_id, 'invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id, 'payment_reference' => $reference, 'amount' => $amount, 'method' => $method, 'paid_at' => now(), 'recorded_by_user_id' => $user->id]);
            $new = $invoice->paid_amount + $amount;
            $invoice->update(['paid_amount' => $new, 'status' => $new === $invoice->total ? 'paid' : $invoice->status, 'paid_at' => $new === $invoice->total ? now() : null]);

            return $payment;
        });
        $invoice->refresh();
        if ($invoice->status === 'paid' && $invoice->connection->status === 'suspended' && $invoice->connection->suspension_reason === 'billing_overdue' && ! Invoice::where('customer_connection_id', $invoice->customer_connection_id)->whereIn('status', ['unpaid', 'overdue'])->where('paid_amount', '<', DB::raw('total'))->exists()) {
            $this->reactivate->handle($invoice->connection, $user, $invoice->id);
        }

return $payment;
    }
}
