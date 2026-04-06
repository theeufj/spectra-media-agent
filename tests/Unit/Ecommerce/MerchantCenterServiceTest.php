<?php

namespace Tests\Unit\Ecommerce;

use App\Services\GoogleAds\MerchantCenterService;
use App\Models\Customer;
use Tests\TestCase;

class MerchantCenterServiceTest extends TestCase
{
    public function test_service_instantiates_with_customer(): void
    {
        $customer = new Customer([
            'name' => 'Test Store',
            'google_ads_customer_id' => '1234567890',
        ]);

        $service = new MerchantCenterService($customer);

        $this->assertInstanceOf(MerchantCenterService::class, $service);
    }

    public function test_normalize_product_returns_expected_structure(): void
    {
        $customer = new Customer(['name' => 'Test']);
        $service = new MerchantCenterService($customer);

        // Use reflection to test the protected normalizeProduct method
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('normalizeProduct');
        $method->setAccessible(true);

        $rawProduct = [
            'offerId' => 'SKU-001',
            'title' => 'Blue Widget',
            'description' => 'A fine widget',
            'link' => 'https://example.com/product/1',
            'imageLink' => 'https://example.com/img/1.jpg',
            'price' => ['value' => '29.99', 'currency' => 'USD'],
            'salePrice' => ['value' => '19.99', 'currency' => 'USD'],
            'availability' => 'in stock',
            'condition' => 'new',
            'brand' => 'WidgetCo',
            'gtin' => '012345678901',
            'mpn' => 'WC-001',
            'googleProductCategory' => 'Home > Widgets',
            'productTypes' => ['Widgets > Blue'],
        ];

        $result = $method->invoke($service, $rawProduct);

        $this->assertEquals('SKU-001', $result['offer_id']);
        $this->assertEquals('Blue Widget', $result['title']);
        $this->assertEquals(29.99, $result['price']);
        $this->assertEquals(19.99, $result['sale_price']);
        $this->assertEquals('USD', $result['currency_code']);
        $this->assertEquals('in_stock', $result['availability']);
        $this->assertEquals('WidgetCo', $result['brand']);
    }
}
