<?php

namespace Tests\Unit\Ecommerce;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductFeedModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_feed_belongs_to_customer(): void
    {
        $customer = Customer::factory()->create();
        $feed = ProductFeed::factory()->create(['customer_id' => $customer->id]);

        $this->assertInstanceOf(Customer::class, $feed->customer);
        $this->assertEquals($customer->id, $feed->customer->id);
    }

    public function test_product_feed_has_many_products(): void
    {
        $feed = ProductFeed::factory()->create();
        Product::factory()->count(3)->create([
            'product_feed_id' => $feed->id,
            'customer_id' => $feed->customer_id,
        ]);

        $this->assertCount(3, $feed->products);
    }

    public function test_health_score_calculation(): void
    {
        $feed = ProductFeed::factory()->create([
            'total_products' => 200,
            'approved_products' => 170,
        ]);

        $this->assertEquals(85, $feed->health_score);
    }

    public function test_health_score_zero_when_no_products(): void
    {
        $feed = ProductFeed::factory()->create([
            'total_products' => 0,
            'approved_products' => 0,
        ]);

        $this->assertEquals(0, $feed->health_score);
    }

    public function test_health_score_100_when_all_approved(): void
    {
        $feed = ProductFeed::factory()->create([
            'total_products' => 50,
            'approved_products' => 50,
        ]);

        $this->assertEquals(100, $feed->health_score);
    }
}
