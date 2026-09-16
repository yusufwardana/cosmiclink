<?php

namespace Database\Factories;

use App\Models\InternetPackage;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class InternetPackageFactory extends Factory
{
    protected $model = InternetPackage::class;

    public function definition(): array
    {
        $speed = fake()->randomElement([10, 20, 50]);

        return ['tenant_id' => Tenant::factory(), 'name' => 'Home '.$speed, 'code' => 'HOME-'.$speed.'-'.fake()->unique()->numberBetween(1, 99999), 'download_mbps' => $speed, 'upload_mbps' => $speed, 'monthly_price' => $speed * 10000, 'network_profile' => 'HOME-'.$speed.'M', 'status' => 'active'];
    }
}
