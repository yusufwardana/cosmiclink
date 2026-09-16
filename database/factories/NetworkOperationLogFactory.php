<?php

namespace Database\Factories;

use App\Models\Router;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class NetworkOperationLogFactory extends Factory
{
    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'router_id' => Router::factory(), 'operation' => 'CREATE_PPPOE', 'target' => 'cust001', 'request_payload' => [], 'result_payload' => ['successful' => true], 'status' => 'success', 'started_at' => now(), 'completed_at' => now(), 'created_at' => now()];
    }
}
