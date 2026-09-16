<?php

namespace App\Actions;

use App\Models\Invoice;
use App\Models\User;

class ProcessOverdueBilling
{
    public function __construct(private readonly SuspendCustomerConnection $suspend) {}

    public function handle(int $tenantId, User $user): int
    {
        $count = 0;
        Invoice::where('tenant_id', $tenantId)->where('status', 'overdue')->where('paid_amount', '<', \DB::raw('total'))->with('connection.networkAccount')->get()->each(function (Invoice $invoice) use ($user, &$count) {
            $connection = $invoice->connection;
            if ($connection && $connection->status === 'active' && $connection->provisioned_at && ! $connection->automationAttempts()->where('action', 'suspend')->where('status', 'success')->exists()) {
                $this->suspend->handle($connection, $invoice, $user);
                $count++;
            }
        });

        return $count;
    }
}
