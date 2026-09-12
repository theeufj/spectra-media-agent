<?php

namespace Tests\Feature;

use App\Notifications\ProviderBillingFailed;
use App\Services\GeminiService;
use App\Support\BillingAlert;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A provider that stops for money must reach a human within minutes.
 *
 * Google moved this project from postpay to prepay without telling anyone. The
 * balance ran dry and every Gemini text call returned 403 BILLING_DISABLED for
 * more than a day — strategy generation, brand extraction, creative, the
 * copilot and the public demo all dead at once — and nothing said so. It was
 * found from a screenshot.
 *
 * Both providers are prepaid, so this recurs; the only variable is how long it
 * takes to notice. It is also the one failure a person fixes in two minutes
 * with a card, which is what makes speed of notice worth more here than almost
 * any other reliability work.
 */
class BillingAlertTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['services.billing_alert_email' => 'ops@example.test']);
    }

    /** @return array<string, array{string, int}> */
    public static function billingFailures(): array
    {
        return [
            // The exact body production returned for a day.
            'google billing disabled' => ['{"error":{"code":403,"message":"This API method requires billing to be enabled. Please enable billing on project #halogen-plasma-487509-e3","status":"PERMISSION_DENIED"}}', 403],
            'payment required' => ['{"error":"Payment Required"}', 402],
            'insufficient credits' => ['{"error":{"message":"Insufficient credits. Add credits to continue."}}', 402],
            'quota exceeded' => ['{"error":{"message":"Quota exceeded for quota metric"}}', 429],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('billingFailures')]
    public function test_a_billing_failure_emails_the_operator(string $body, int $status): void
    {
        Notification::fake();

        $this->assertTrue(BillingAlert::check('Gemini', $body, $status));

        Notification::assertSentOnDemand(
            ProviderBillingFailed::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'ops@example.test',
        );
    }

    /** @return array<string, array{string, int}> */
    public static function ordinaryFailures(): array
    {
        return [
            'a bad prompt' => ['{"error":{"message":"Invalid argument: contents must not be empty"}}', 400],
            'a server wobble' => ['{"error":{"message":"Internal error encountered"}}', 500],
            'model not found' => ['{"error":{"message":"Publisher Model not found"}}', 404],
            'nothing at all' => ['', 500],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ordinaryFailures')]
    public function test_an_ordinary_failure_does_not_email_anyone(string $body, int $status): void
    {
        // Waking someone for a malformed prompt is how an alert stops being read.
        Notification::fake();

        $this->assertFalse(BillingAlert::check('Gemini', $body, $status));

        Notification::assertNothingSent();
    }

    public function test_a_sustained_outage_sends_one_email_an_hour_not_thousands(): void
    {
        Notification::fake();
        $body = '{"error":{"message":"This API method requires billing to be enabled."}}';

        for ($i = 0; $i < 20; $i++) {
            BillingAlert::check('Gemini', $body, 403);
        }

        Notification::assertSentOnDemandTimes(ProviderBillingFailed::class, 1);
    }

    public function test_each_provider_alerts_separately(): void
    {
        Notification::fake();

        BillingAlert::check('Gemini', 'billing to be enabled', 403);
        BillingAlert::check('OpenRouter', 'Insufficient credits', 402);

        // One balance running dry says nothing about the other, and the backup
        // failing is exactly when you most need to hear about it.
        Notification::assertSentOnDemandTimes(ProviderBillingFailed::class, 2);
    }

    public function test_the_alert_fires_from_a_real_gemini_call(): void
    {
        Notification::fake();
        Cache::put('gcp_vertex_access_token', 'test-token', 600);
        config(['services.openrouter.api_key' => null]);

        Http::fake(['*' => Http::response([
            'error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'This API method requires billing to be enabled.'],
        ], 403)]);

        app(GeminiService::class)->generateContent('gemini-3.7-flash', 'anything', [], null, false, false, 1);

        Notification::assertSentOnDemand(ProviderBillingFailed::class);
    }

    public function test_no_recipient_configured_is_silent_rather_than_fatal(): void
    {
        config(['services.billing_alert_email' => null]);
        Notification::fake();

        BillingAlert::check('Gemini', 'billing to be enabled', 403);

        Notification::assertNothingSent();
    }
}
