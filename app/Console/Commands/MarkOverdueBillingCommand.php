<?php

namespace App\Console\Commands;

use App\Actions\MarkOverdueInvoices;
use Illuminate\Console\Command;

class MarkOverdueBillingCommand extends Command
{
    protected $signature = 'billing:mark-overdue';

    protected $description = 'Mark unpaid invoices past their due date as overdue.';

    public function handle(MarkOverdueInvoices $mark): int
    {
        $this->info('Marked '.$mark->handle().' invoice(s) overdue.');

        return self::SUCCESS;
    }
}
