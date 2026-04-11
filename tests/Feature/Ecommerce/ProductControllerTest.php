<?php

namespace Tests\Feature\Ecommerce;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductFeed;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->customer = Customer::factory()->create();
        $this->user = User::factory()->create(['customer_id' => $this->customer->id]);
        $this->user->customers()->attach($this->customer->id, ['role' => 'owner']);
    }

    protected function authenticatedGet(string $uri)
    {
        return $this->actingAs($this->user)
            ->withSession(['active_customer_id' => $this->customer->id])
            ->get($uri);
    }

    protected function authenticatedPost(string $uri, array $data = [])
    {
        return $this->actingAs($this->user)
            ->withSession(['active_customer_id' => $this->customer->id])
            ->post($uri, $data);
    }

    protected function authenticatedDelete(string $uri)
    {
        return $this->actingAs($this->user)
            ->withSession(['active_customer_id' => $this->customer->id])
            ->delete($uri);
    }

    public function test_products_index_shows_feeds_and_stats(): void
    {
        $feed = ProductFeed::factory()->create(['customer_id' => $this->customer->id]);
        Product::factory()->count(5)->approved()->create([
            'product_feed_id' => $feed->id,
            'customer_id' => $this->customer->id,
        ]);
        Product::factory()->count(2)->disapproved()->create([
            'product_feed_id' => $feed->id,
            'customer_id' => $this->customer->id,
        ]);

        $response = $this->authenticatedGet('/products');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Products/Index')
            ->has('feeds', 1)
            ->has('stats')
            ->where('stats.total', 7)
            ->where('stats.approved', 5)
            ->where('stats.disapproved', 2)
        );
    }

    public function test_create_feed_validates_required_fields(): void
    {
        $response = $this->authenticatedPost('/products/feeds', []);

        $response->assertSessionHasErrors(['feed_name', 'merchant_id', 'source_type']);
    }

    public function test_create_feed_succeeds_with_valid_data(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $response = $this->authenticatedPost('/products/feeds', [
            'feed_name' => 'My Store Feed',
            'merchant_id' => '12345678',
            'source_type' => 'api',
            'sync_frequency' => 'daily',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('product_feeds', [
            'customer_id' => $this->customer->id,
            'feed_name' => 'My Store Feed',
            'merchant_id' => '12345678',
            'source_type' => 'api',
            'status' => 'pending',
        ]);
    }

    public function test_create_feed_rejects_invalid_source_type(): void
    {
        $response = $this->authenticatedPost('/products/feeds', [
            'feed_name' => 'Test',
            'merchant_id' => '123',
            'source_type' => 'invalid',
        ]);

        $response->assertSessionHasErrors('source_type');
    }

    public function test_sync_feed_dispatches_job(): void
    {
        $feed = ProductFeed::factory()->create(['customer_id' => $this->customer->id]);

        $response = $this->authenticatedPost("/products/feeds/{$feed->id}/sync");

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_sync_feed_forbidden_for_other_customer(): void
    {
        $otherCustomer = Customer::factory()->create();
        $feed = ProductFeed::factory()->create(['customer_id' => $otherCustomer->id]);

        $response = $this->authenticatedPost("/products/feeds/{$feed->id}/sync");

        $response->assertForbidden();
    }

    public function test_delete_feed_removes_record(): void
    {
        $feed = ProductFeed::factory()->create(['customer_id' => $this->customer->id]);

        $response = $this->authenticatedDelete("/products/feeds/{$feed->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('product_feeds', ['id' => $feed->id]);
    }

    public function test_delete_feed_forbidden_for_other_customer(): void
    {
        $otherCustomer = Customer::factory()->create();
        $feed = ProductFeed::factory()->create(['customer_id' => $otherCustomer->id]);

        $response = $this->authenticatedDelete("/products/feeds/{$feed->id}");

        $response->assertForbidden();
    }

    public function test_product_list_page(): void
    {
        $feed = ProductFeed::factory()->create(['customer_id' => $this->customer->id]);
        Product::factory()->count(3)->create([
            'product_feed_id' => $feed->id,
            'customer_id' => $this->customer->id,
        ]);

        $response = $this->authenticatedGet('/products/list');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Products/List')
            ->has('products', 3)
        );
    }

    public function test_product_list_filters_by_status(): void
    {
        $feed = ProductFeed::factory()->create(['customer_id' => $this->customer->id]);
        Product::factory()->count(3)->approved()->create([
            'product_feed_id' => $feed->id,
            'customer_id' => $this->customer->id,
        ]);
        Product::factory()->count(2)->disapproved()->create([
            'product_feed_id' => $feed->id,
            'customer_id' => $this->customer->id,
        ]);

        $response = $this->authenticatedGet('/products/list?status=disapproved');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Products/List')
            ->has('products', 2)
        );
    }
}
