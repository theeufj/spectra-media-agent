<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFeedFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'merchant_id' => (string) $this->faker->randomNumber(8),
            'feed_name' => $this->faker->company() . ' Feed',
            'source_type' => $this->faker->randomElement(['manual', 'url', 'api', 'shopify', 'woocommerce']),
            'source_url' => $this->faker->url(),
            'status' => 'active',
            'total_products' => 100,
            'approved_products' => 85,
            'disapproved_products' => 5,
            'last_synced_at' => now(),
            'sync_frequency' => 'daily',
        ];
    }
}
