<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'invoice_id' => Invoice::factory(), 'customer_id' => Customer::factory(), 'payment_reference' => 'PAY-'.fake()->unique()->numerify('######'), 'amount' => 100000, 'method' => 'manual', 'paid_at' => now(), 'status' => 'confirmed'];
    }
}
