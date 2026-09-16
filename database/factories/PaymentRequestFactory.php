<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentRequestFactory extends Factory
{
    protected $model = PaymentRequest::class;

    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'invoice_id' => Invoice::factory(), 'customer_id' => Customer::factory(), 'provider' => 'fake', 'provider_reference' => 'PAY-DEMO-'.$this->faker->unique()->numerify('######'), 'amount' => 150000, 'currency' => 'IDR', 'status' => 'pending', 'payment_url' => 'SIMULATED PAYMENT', 'qr_payload' => 'SIMULATED-PAYMENT', 'expires_at' => now()->addDay(), 'metadata' => ['simulation' => true]];
    }
}
