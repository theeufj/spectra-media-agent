<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\ProductFeed;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_feed_id' => ProductFeed::factory(),
            'customer_id' => Customer::factory(),
            'offer_id' => 'SKU-' . $this->faker->unique()->numerify('####'),
            'title' => $this->faker->words(4, true),
            'description' => $this->faker->sentence(),
            'link' => $this->faker->url(),
            'image_link' => $this->faker->imageUrl(),
            'price' => $this->faker->randomFloat(2, 5, 500),
            'sale_price' => null,
            'currency_code' => 'USD',
            'availability' => 'in_stock',
            'condition' => 'new',
            'brand' => $this->faker->company(),
            'gtin' => $this->faker->ean13(),
            'status' => 'approved',
            'impressions' => $this->faker->randomFloat(0, 0, 10000),
            'clicks' => $this->faker->randomFloat(0, 0, 500),
            'cost' => $this->faker->randomFloat(2, 0, 200),
            'conversions' => $this->faker->randomFloat(0, 0, 50),
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => 'approved']);
    }

    public function disapproved(): static
    {
        return $this->state([
            'status' => 'disapproved',
            'disapproval_reasons' => ['Missing GTIN', 'Invalid price'],
        ]);
    }

    public function outOfStock(): static
    {
        return $this->state(['availability' => 'out_of_stock']);
    }
}
