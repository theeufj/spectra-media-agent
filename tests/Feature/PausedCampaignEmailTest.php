<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\DeploymentCompleted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * A one-time setup campaign is not live, and the email must not say it is.
 *
 * SettleDeployedCampaign pauses it deliberately as it deploys, the receipt
 * promises it arrives paused, and the Create button says so in as many words.
 * Then this landed in the customer's inbox the moment the deploy finished:
 *
 *   Subject: Your campaign is live: …
 *   "Your campaign is now live and your ads are running."
 *   "Your ads are scheduled to begin serving from tomorrow."
 *
 * Someone reading that believes money is going out tonight, when the whole
 * proposition they bought is that they choose when it starts. Caught by
 * reading the customer's own email log after a real end-to-end deploy.
 */
class PausedCampaignEmailTest extends TestCase
{
    use DatabaseTransactions;

    private function render(string $serviceType): string
    {
        $customer = Customer::factory()->create(['service_type' => $serviceType]);
        $user = User::factory()->create();
        $customer->users()->attach($user->id, ['role' => 'owner']);
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'AI Ecommerce Store Builder',
            'daily_budget' => 40,
        ]);

        $mail = (new DeploymentCompleted($campaign->fresh('customer'), 1, 0, []))->toMail($user);

        return json_encode([$mail->subject, $mail->introLines, $mail->outroLines]);
    }

    public function test_a_setup_only_customer_is_never_told_their_ads_are_running(): void
    {
        $rendered = $this->render('setup_only');

        $this->assertStringNotContainsString('is live', $rendered);
        $this->assertStringNotContainsString('ads are running', $rendered);
        $this->assertStringNotContainsString('begin serving from tomorrow', $rendered);

        // What is actually true.
        $this->assertStringContainsString('paused', $rendered);
        $this->assertStringContainsString('switch it on', $rendered);
    }

    public function test_a_managed_customer_is_still_told_their_campaign_is_live(): void
    {
        // Their ads genuinely are running, and saying so is the point of the
        // email. The setup-only branch must not swallow that.
        $rendered = $this->render('managed');

        $this->assertStringContainsString('is live', $rendered);
        $this->assertStringContainsString('ads are running', $rendered);
    }
}
