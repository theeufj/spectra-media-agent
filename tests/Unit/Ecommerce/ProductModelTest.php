<?php

namespace Tests\Unit\Ecommerce;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_belongs_to_feed(): void
    {
        $feed = ProductFeed::factory()->create();
        $product = Product::factory()->create([
            'product_feed_id' => $feed->id,
            'customer_id' => $feed->customer_id,
        ]);

        $this->assertInstanceOf(ProductFeed::class, $product->productFeed);
        $this->assertEquals($feed->id, $product->productFeed->id);
    }

    public function test_product_belongs_to_customer(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['customer_id' => $customer->id]);

        $this->assertInstanceOf(Customer::class, $product->customer);
        $this->assertEquals($customer->id, $product->customer->id);
    }

    public function test_approved_scope(): void
    {
        $feed = ProductFeed::factory()->create();
        $customerId = $feed->customer_id;

        Product::factory()->count(3)->approved()->create([
            'product_feed_id' => $feed->id,
            'customer_id' => $customerId,
        ]);
        Product::factory()->count(2)->disapproved()->create([
            'product_feed_id' => $feed->id,
            'customer_id' => $customerId,
        ]);

        $this->assertCount(3, Product::approved()->get());
    }

    public function test_disapproved_scope(): void
    {
        $feed = ProductFeed::factory()->create();
        $customerId = $feed->customer_id;

        Product::factory()->count(3)->approved()->create([
            'product_feed_id' => $feed->id,
            'customer_id' => $customerId,
        ]);
        Product::factory()->count(2)->disapproved()->create([
            'product_feed_id' => $feed->id,
            'customer_id' => $customerId,
        ]);

        $this->assertCount(2, Product::disapproved()->get());
    }

    public function test_in_stock_scope(): void
    {
        $feed = ProductFeed::factory()->create();
        $customerId = $feed->customer_id;

        Product::factory()->count(4)->create([
            'product_feed_id' => $feed->id,
            'customer_id' => $customerId,
            'availability' => 'in_stock',
        ]);
        Product::factory()->count(1)->outOfStock()->create([
            'product_feed_id' => $feed->id,
            'customer_id' => $customerId,
        ]);

        $this->assertCount(4, Product::inStock()->get());
    }

    public function test_disapproval_reasons_cast_to_array(): void
    {
        $reasons = ['Missing GTIN', 'Invalid price'];
        $product = Product::factory()->create([
            'disapproval_reasons' => $reasons,
        ]);

        $product->refresh();
        $this->assertIsArray($product->disapproval_reasons);
        $this->assertEquals($reasons, $product->disapproval_reasons);
    }
}
