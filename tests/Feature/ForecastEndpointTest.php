<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The forecast panel's two endpoints.
 *
 * One reads Google's view of the customer's market; the other records the one
 * number Google cannot tell us. Both are reached with an `active_customer_id`
 * out of the session, which is exactly the shape that invites a tampered id, so
 * the customer is resolved through the user's own relation rather than looked
 * up by id and checked afterwards.
 */
class ForecastEndpointTest extends TestCase
{
    use DatabaseTransactions;

    private const SITE = 'https://example.com';

    /** @return array{User, Customer} */
    private function signedIn(array $customerAttributes = []): array
    {
        $customer = Customer::factory()->create(array_merge([
            'website' => self::SITE,
            'country' => 'US',
            'currency_code' => 'USD',
        ], $customerAttributes));

        $user = User::factory()->create(['email_verified_at' => now()]);
        $customer->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id]);

        return [$user, $customer];
    }

    private function seedForecast(Customer $customer): void
    {
        Cache::put(sprintf('market_forecast:%d:%s', $customer->id, md5(self::SITE)), [
            'keywords' => [['keyword' => 'emergency plumber', 'monthly_searches' => 2400, 'cpc' => 4.10]],
            'max_cpc' => 4.10, 'days' => 30, 'conversion_rate' => 0.03,
            'impressions' => 40000, 'clicks' => 1000, 'cost' => 1000.0,
            'conversions' => 30.0, 'average_cpc' => 1.0, 'ctr' => 0.025,
        ], now()->addHour());
    }

    public function test_a_guest_cannot_read_a_forecast(): void
    {
        $this->getJson(route('api.forecast.show'))->assertUnauthorized();
    }

    public function test_a_guest_cannot_record_an_order_value(): void
    {
        $this->postJson(route('api.forecast.order-value'), ['average_order_value' => 100])
            ->assertUnauthorized();
    }

    public function test_the_forecast_comes_back_with_googles_figures(): void
    {
        [, $customer] = $this->signedIn();
        $this->seedForecast($customer);

        $this->getJson(route('api.forecast.show', ['monthly_budget' => 5000]))
            ->assertOk()
            ->assertJsonPath('forecast.clicks', 1000)
            ->assertJsonPath('forecast.currency', 'USD')
            ->assertJsonPath('forecast.has_order_value', false);
    }

    public function test_recording_an_order_value_returns_the_revenue_it_unlocks(): void
    {
        [, $customer] = $this->signedIn();
        $this->seedForecast($customer);

        $this->postJson(route('api.forecast.order-value'), ['average_order_value' => 180])
            ->assertOk()
            ->assertJsonPath('forecast.has_order_value', true)
            ->assertJsonPath('forecast.order_value', 180);

        $this->assertSame(180.0, (float) $customer->fresh()->average_order_value);
    }

    /** @return array<string, array{mixed}> */
    public static function refusedOrderValues(): array
    {
        return [
            'zero' => [0],
            'negative' => [-50],
            'absurd' => [9_999_999],
            'not a number' => ['a lot'],
            'missing' => [null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedOrderValues')]
    public function test_an_implausible_order_value_is_refused(mixed $value): void
    {
        // It does not stay on the page: BudgetIntelligenceAgent reallocates
        // real budget against this number once it is set.
        [, $customer] = $this->signedIn();

        $this->postJson(route('api.forecast.order-value'), ['average_order_value' => $value])
            ->assertStatus(422);

        $this->assertNull($customer->fresh()->average_order_value);
    }

    public function test_a_session_pointing_at_someone_elses_customer_resolves_to_nothing(): void
    {
        $stranger = Customer::factory()->create(['website' => self::SITE]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $own = Customer::factory()->create(['website' => self::SITE]);
        $own->users()->attach($user->id, ['role' => 'owner']);

        // A tampered session id must not reach a customer this user has no
        // claim on.
        $this->actingAs($user)
            ->withSession(['active_customer_id' => $stranger->id])
            ->postJson(route('api.forecast.order-value'), ['average_order_value' => 250])
            ->assertNotFound();

        $this->assertNull($stranger->fresh()->average_order_value);
    }

    public function test_no_active_customer_is_an_empty_forecast_not_an_error(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->getJson(route('api.forecast.show'))
            ->assertOk()
            ->assertJsonPath('forecast', null);
    }

    public function test_a_campaign_belonging_to_another_customer_is_ignored(): void
    {
        [, $customer] = $this->signedIn();
        $this->seedForecast($customer);

        $foreign = \App\Models\Campaign::factory()->create(['daily_budget' => 500.00]);

        // Falls back to the default framing rather than adopting a budget from
        // a campaign this customer does not own.
        $budget = $this->getJson(route('api.forecast.show', ['campaign_id' => $foreign->id]))
            ->assertOk()
            ->json('forecast.budget');

        $this->assertSame((float) config('demo.monthly_budget'), (float) $budget);
    }
}
