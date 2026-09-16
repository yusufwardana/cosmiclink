<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class RouterFactory extends Factory
{
    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'name' => fake()->words(2, true), 'host' => 'placeholder.local', 'api_port' => 8728, 'username' => 'admin', 'status' => 'available', 'driver' => 'fake'];
    }
}
