<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Campaign>
 */
class CampaignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => $this->faker->sentence(3),
            'reason' => $this->faker->paragraph(),
            'goals' => $this->faker->sentence(),
            'target_market' => $this->faker->sentence(),
            'voice' => $this->faker->word(),
            'total_budget' => $this->faker->randomFloat(2, 500, 10000),
            'daily_budget' => $this->faker->randomFloat(2, 20, 200),
            'start_date' => now(),
            'end_date' => now()->addDays(30),
            'primary_kpi' => '4x ROAS',
            'product_focus' => $this->faker->sentence(),
        ];
    }
}
