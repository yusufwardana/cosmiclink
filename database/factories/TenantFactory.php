<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class TenantFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return ['name' => $name, 'slug' => str($name)->slug().'-'.fake()->unique()->numberBetween(1, 99999)];
    }
}
