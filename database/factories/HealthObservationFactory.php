<?php

namespace Database\Factories;

use App\Models\HealthObservation;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class HealthObservationFactory extends Factory
{
    protected $model = HealthObservation::class;

    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'subject_type' => 'router', 'subject_id' => 1, 'health_state' => 'online', 'reachable' => true, 'online' => null, 'latency_ms' => 20, 'packet_loss_percent' => 0, 'observed_at' => now(), 'provider' => 'fake', 'metadata' => ['simulation' => true]];
    }
}
