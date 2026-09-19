<?php

namespace App\Services\Network;

use App\Models\NetworkAccount;
use App\Models\NetworkOperationLog;
use App\Models\Router;

class NetworkOperationSafetyQuery
{
    private const BLOCKING_STATUSES = ['reserved', 'preflighting', 'executing', 'verifying', 'unknown', 'postflight_mismatch'];

    public function hasUnresolvedState(NetworkAccount|Router $subject): bool
    {
        $query = NetworkOperationLog::query()
            ->where('tenant_id', $subject->tenant_id)
            ->whereIn('status', self::BLOCKING_STATUSES)
            ->whereNull('resolved_at');

        if ($subject instanceof NetworkAccount) {
            $query->where(function ($query) use ($subject): void {
                $query->where('network_account_id', $subject->id)
                    ->orWhere(function ($query) use ($subject): void {
                        $query->whereNull('network_account_id')->where('router_id', $subject->router_id);
                    });
            });
        } else {
            $query->where('router_id', $subject->id);
        }

        return $query->exists();
    }
}
