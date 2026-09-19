<?php

namespace App\Actions;

use App\Models\BillingAutomationAttempt;
use App\Models\CustomerConnection;
use App\Models\User;
use App\Services\Network\NetworkOperationService;

class ReactivateCustomerConnection
{
    public function __construct(private readonly NetworkOperationService $operations, private readonly SendCustomerMessage $messenger) {}

    public function handle(CustomerConnection $connection, User $user, ?int $invoiceId = null): BillingAutomationAttempt
    {
        abort_unless(! data_get($connection->networkAccount?->metadata, 'adopted_from_discovery', false), 422, 'Adopted accounts are read-only.');
        $attempt = BillingAutomationAttempt::create(['tenant_id' => $connection->tenant_id, 'invoice_id' => $invoiceId, 'customer_connection_id' => $connection->id, 'action' => 'reactivate', 'status' => 'pending', 'attempted_at' => now()]);
        $result = $this->operations->changeStatusForBilling($connection->networkAccount, 'active', $user, $connection);
        if ($result->successful) {
            $connection->update(['status' => 'active', 'suspended_at' => null, 'suspension_reason' => null]);
            $attempt->update(['status' => 'success', 'completed_at' => now()]);
            if ($connection->customer->tenant_id === $user->tenant_id) {
                $this->messenger->handle($connection->customer, 'service_reactivated', [], $user, null, $connection);
            }
        } else {
            $attempt->update(['status' => 'failed', 'completed_at' => now(), 'failure_code' => $result->errorCode, 'failure_message' => $result->message]);
        }

        return $attempt;
    }
}
