<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\MessageLog;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessageLogFactory extends Factory
{
    protected $model = MessageLog::class;

    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'customer_id' => Customer::factory(), 'channel' => 'whatsapp', 'provider' => 'fake', 'recipient' => '+6281234567890', 'template' => 'payment_reminder', 'rendered_content' => 'Simulated message.', 'status' => 'sent', 'provider_message_id' => 'MSG-DEMO-'.$this->faker->unique()->numerify('######'), 'attempted_at' => now(), 'sent_at' => now(), 'metadata' => ['simulation' => true]];
    }
}
