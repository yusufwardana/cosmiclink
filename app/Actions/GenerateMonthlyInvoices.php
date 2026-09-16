<?php

namespace App\Actions;

use App\Models\CustomerConnection;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Carbon;

class GenerateMonthlyInvoices
{
    public function __construct(private readonly GenerateInvoiceForConnection $generate) {}

    public function handle(int $tenantId, Carbon $periodStart, ?User $user = null): int
    {
        $count = 0;
        CustomerConnection::where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'suspended'])
            ->whereNotNull('provisioned_at')
            ->with(['customer', 'internetPackage'])
            ->get()
            ->each(function (CustomerConnection $connection) use ($periodStart, $user, &$count): void {
                $before = Invoice::where('customer_connection_id', $connection->id)->whereDate('billing_period_start', $periodStart->copy()->startOfMonth())->exists();
                $this->generate->handle($connection, $periodStart, $user);
                $count += $before ? 0 : 1;
            });

        return $count;
    }
}
