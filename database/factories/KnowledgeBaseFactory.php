<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeBase>
 */
class KnowledgeBaseFactory extends Factory
{
    protected $model = KnowledgeBase::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'customer_id' => Customer::factory(),
            'url' => $this->faker->url(),
            'content' => $this->faker->paragraph(),
            'source_type' => 'crawl',
        ];
    }
}
