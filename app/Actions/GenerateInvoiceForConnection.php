<?php

namespace App\Actions;

use App\Models\CustomerConnection;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GenerateInvoiceForConnection
{
    public function handle(CustomerConnection $connection, Carbon $periodStart, ?User $user = null): Invoice
    {
        abort_unless($user === null || $user->tenant_id === $connection->tenant_id, 403);
        $connection->loadMissing(['customer', 'internetPackage']);
        abort_unless(in_array($connection->status, ['active', 'suspended'], true) && $connection->provisioned_at, 422, 'Connection is not billable.');
        $start = $periodStart->copy()->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $existing = Invoice::where('tenant_id', $connection->tenant_id)->where('customer_connection_id', $connection->id)->whereDate('billing_period_start', $start)->whereDate('billing_period_end', $end)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($connection, $start, $end) {
            $total = $connection->internetPackage->monthly_price;
            $invoice = Invoice::create(['tenant_id' => $connection->tenant_id, 'customer_id' => $connection->customer_id, 'customer_connection_id' => $connection->id, 'billing_period_start' => $start, 'billing_period_end' => $end, 'issue_date' => now()->toDateString(), 'due_date' => $end->copy()->addDays(7)->toDateString(), 'subtotal' => $total, 'discount' => 0, 'total' => $total, 'status' => 'unpaid']);
            $invoice->items()->create(['customer_connection_id' => $connection->id, 'internet_package_id' => $connection->internet_package_id, 'description' => $connection->internetPackage->name.' ('.$connection->internetPackage->download_mbps.'/'.$connection->internetPackage->upload_mbps.' Mbps)', 'quantity' => 1, 'unit_price' => $total, 'amount' => $total]);

            return $invoice;
        });
    }
}
