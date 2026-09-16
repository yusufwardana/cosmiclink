<?php

namespace Database\Factories;

use App\Models\OutageIncident;
use App\Models\Router;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class OutageIncidentFactory extends Factory
{
    protected $model = OutageIncident::class;

    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'router_id' => Router::factory(), 'status' => 'detected', 'detected_at' => now(), 'correlation_count' => 3, 'evidence_window_minutes' => 10];
    }
}
