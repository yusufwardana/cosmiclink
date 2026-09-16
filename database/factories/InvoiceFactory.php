<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $start = now()->startOfMonth();

        return [
            'tenant_id' => Tenant::factory(),
            'customer_id' => Customer::factory(),
            'customer_connection_id' => CustomerConnection::factory(),
            'invoice_number' => null,
            'billing_period_start' => $start,
            'billing_period_end' => $start->copy()->endOfMonth(),
            'issue_date' => $start,
            'due_date' => $start->copy()->addDays(7),
            'subtotal' => 150000,
            'discount' => 0,
            'total' => 150000,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ];
    }
}
