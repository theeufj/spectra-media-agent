<?php

namespace Database\Factories;

use App\Models\AgentActivity;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentActivity>
 */
class AgentActivityFactory extends Factory
{
    protected $model = AgentActivity::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'campaign_id' => null,
            'agent_type' => $this->faker->randomElement(['optimization', 'deployment', 'maintenance']),
            'action' => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
            'details' => null,
            'status' => 'completed',
        ];
    }
}
