<?php

namespace App\Actions;

use App\Models\BillingAutomationAttempt;
use App\Models\CustomerConnection;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Network\NetworkOperationService;

class SuspendCustomerConnection
{
    public function __construct(private readonly NetworkOperationService $operations, private readonly SendCustomerMessage $messenger) {}

    public function handle(CustomerConnection $connection, ?Invoice $invoice, User $user): BillingAutomationAttempt
    {
        $attempt = BillingAutomationAttempt::create(['tenant_id' => $connection->tenant_id, 'invoice_id' => $invoice?->id, 'customer_connection_id' => $connection->id, 'action' => 'suspend', 'status' => 'pending', 'attempted_at' => now()]);
        $result = $this->operations->changeStatus($connection->networkAccount, 'disabled', $user, $connection);
        if ($result->successful) {
            $connection->update(['status' => 'suspended', 'suspended_at' => now(), 'suspension_reason' => 'billing_overdue']);
            $attempt->update(['status' => 'success', 'completed_at' => now()]);
            if ($connection->customer->tenant_id === $user->tenant_id) {
                $this->messenger->handle($connection->customer, 'service_suspended', [], $user, $invoice, $connection);
            }
        } else {
            $connection->update(['metadata' => array_merge($connection->metadata ?? [], ['last_billing_automation_status' => 'failed'])]);
            $attempt->update(['status' => 'failed', 'completed_at' => now(), 'failure_code' => $result->errorCode, 'failure_message' => $result->message]);
        }

        return $attempt;
    }
}
