<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\InternetPackage;
use App\Models\Router;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerConnectionFactory extends Factory
{
    protected $model = CustomerConnection::class;

    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'customer_id' => Customer::factory(), 'internet_package_id' => InternetPackage::factory(), 'router_id' => Router::factory(), 'status' => 'pending'];
    }
}
