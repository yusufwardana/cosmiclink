<?php

namespace App\Actions;

use App\Models\Invoice;
use Illuminate\Support\Carbon;

class MarkOverdueInvoices
{
    public function handle(?int $tenantId = null): int
    {
        return Invoice::where('status', 'unpaid')->where('paid_amount', '<', \DB::raw('total'))->whereDate('due_date', '<', Carbon::today())->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->update(['status' => 'overdue']);
    }
}
