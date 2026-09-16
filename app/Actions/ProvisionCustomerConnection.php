<?php

namespace App\Actions;

use App\Models\CustomerConnection;
use App\Models\NetworkAccount;
use App\Models\User;
use App\Services\Network\NetworkOperationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProvisionCustomerConnection
{
    public function __construct(private readonly NetworkOperationService $operations) {}

    public function handle(CustomerConnection $connection, User $user): void
    {
        abort_unless($connection->tenant_id === $user->tenant_id, 403);
        $connection->loadMissing(['customer', 'internetPackage', 'router', 'networkAccount']);
        if ($connection->status === 'active') {
            return;
        }
        DB::transaction(fn () => $connection->update(['status' => 'provisioning', 'failure_code' => null, 'failure_message' => null]));
        $account = $connection->networkAccount;
        if (! $account) {
            $account = NetworkAccount::firstOrCreate(
                ['tenant_id' => $connection->tenant_id, 'router_id' => $connection->router_id, 'username' => $connection->customer->customer_code],
                ['profile' => $connection->internetPackage->network_profile, 'status' => 'disabled', 'metadata' => ['connection_id' => $connection->id]]
            );
            if (! $account->encrypted_secret) {
                $account->setSecret(Str::random(48));
                $account->save();
            }
            DB::transaction(fn () => $connection->update(['network_account_id' => $account->id]));
        }
        $result = $this->operations->createAccount($connection->router, ['username' => $account->username, 'profile' => $connection->internetPackage->network_profile, 'password' => $account->secret()], $user, $connection);
        if ($result->successful) {
            DB::transaction(fn () => $connection->update(['status' => 'active', 'provisioned_at' => now(), 'failure_code' => null, 'failure_message' => null, 'failed_at' => null]));
            $account->update(['status' => 'active', 'profile' => $connection->internetPackage->network_profile]);
        } else {
            DB::transaction(fn () => $connection->update(['status' => 'failed', 'failed_at' => now(), 'failure_code' => $result->errorCode, 'failure_message' => $result->message]));
        }
    }
}
