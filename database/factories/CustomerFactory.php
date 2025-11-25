<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'business_type' => 'B2B',
            'description' => $this->faker->sentence(),
            'country' => 'US',
            'timezone' => 'UTC',
            'currency_code' => 'USD',
            'website' => $this->faker->url(),
            'phone' => $this->faker->phoneNumber(),
        ];
    }
}
