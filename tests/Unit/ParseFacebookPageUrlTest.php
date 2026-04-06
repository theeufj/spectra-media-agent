<?php

namespace Tests\Unit;

use App\Models\Customer;
use PHPUnit\Framework\TestCase;

class ParseFacebookPageUrlTest extends TestCase
{
    public function test_parses_profile_php_url_with_numeric_id(): void
    {
        $result = Customer::parseFacebookPageUrl('https://www.facebook.com/profile.php?id=61584812770566');

        $this->assertNotNull($result);
        $this->assertEquals('61584812770566', $result['page_id']);
    }

    public function test_parses_vanity_url(): void
    {
        $result = Customer::parseFacebookPageUrl('https://www.facebook.com/Proveably');

        $this->assertNotNull($result);
        $this->assertEquals('Proveably', $result['page_id']);
        $this->assertEquals('Proveably', $result['page_name']);
    }

    public function test_parses_vanity_url_with_trailing_slash(): void
    {
        $result = Customer::parseFacebookPageUrl('https://facebook.com/MyBusiness/');

        $this->assertNotNull($result);
        $this->assertEquals('MyBusiness', $result['page_id']);
    }

    public function test_parses_p_format_url_with_id(): void
    {
        $result = Customer::parseFacebookPageUrl('https://www.facebook.com/p/PageName-61584812770566/');

        $this->assertNotNull($result);
        $this->assertEquals('61584812770566', $result['page_id']);
        $this->assertEquals('PageName', $result['page_name']);
    }

    public function test_parses_raw_numeric_id(): void
    {
        $result = Customer::parseFacebookPageUrl('61584812770566');

        $this->assertNotNull($result);
        $this->assertEquals('61584812770566', $result['page_id']);
        $this->assertNull($result['page_name']);
    }

    public function test_parses_numeric_id_in_url_path(): void
    {
        $result = Customer::parseFacebookPageUrl('https://www.facebook.com/61584812770566');

        $this->assertNotNull($result);
        $this->assertEquals('61584812770566', $result['page_id']);
    }

    public function test_returns_null_for_empty_input(): void
    {
        $this->assertNull(Customer::parseFacebookPageUrl(''));
        $this->assertNull(Customer::parseFacebookPageUrl(null));
    }

    public function test_handles_url_with_http(): void
    {
        $result = Customer::parseFacebookPageUrl('http://facebook.com/TestPage');

        $this->assertNotNull($result);
        $this->assertEquals('TestPage', $result['page_id']);
    }

    public function test_treats_plain_string_as_slug(): void
    {
        $result = Customer::parseFacebookPageUrl('my-page-slug');

        $this->assertNotNull($result);
        $this->assertEquals('my-page-slug', $result['page_id']);
        $this->assertNull($result['page_name']);
    }
}
