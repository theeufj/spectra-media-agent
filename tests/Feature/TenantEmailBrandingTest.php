<?php

namespace Tests\Feature;

use App\Mail\FirstCampaignReady;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use App\Models\VideoCollateral;
use App\Notifications\DeploymentCompleted;
use App\Services\VideoGeneration\VideoPostCompletion;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Lifecycle email must speak the skin the customer signed up under. Every
 * mailable resolves its tenant from the models it carries (AppMailable),
 * notifications via the TenantAware trait — a realpropertyads customer gets
 * Real Property Ads branding and realpropertyads.com links, not Site to
 * Spend. Also pins the per-video email flood fix: VideosGenerated only
 * sends once nothing is left pending.
 */
class TenantEmailBrandingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_mailable_for_a_tenant_customer_renders_tenant_brand_and_links(): void
    {
        $customer = Customer::factory()->create(['tenant_key' => 'realpropertyads']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        $html = (new FirstCampaignReady($campaign->fresh('customer'), 'Josh'))->render();

        $this->assertStringContainsString('Real Property Ads', $html);
        $this->assertStringContainsString('https://realpropertyads.com/campaigns/'.$campaign->id.'/strategies', $html);
        $this->assertStringNotContainsString('sitetospend.com/campaigns', $html);
    }

    public function test_mailable_for_a_default_customer_keeps_default_brand(): void
    {
        $customer = Customer::factory()->create(['tenant_key' => null]);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        $html = (new FirstCampaignReady($campaign->fresh('customer'), 'Josh'))->render();

        $this->assertStringContainsString('Site to Spend', $html);
        $this->assertStringNotContainsString('realpropertyads.com', $html);
    }

    public function test_notification_links_and_salutation_follow_the_tenant(): void
    {
        $customer = Customer::factory()->create(['tenant_key' => 'realpropertyads']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $user = User::factory()->create();

        $mail = (new DeploymentCompleted($campaign->fresh('customer'), successCount: 1, failureCount: 0, strategies: []))
            ->toMail($user);

        $this->assertStringContainsString('https://realpropertyads.com/dashboard', $mail->actionUrl);
        $this->assertStringContainsString('Real Property Ads Team', $mail->salutation);
    }

    public function test_tenant_url_helper_defaults_to_app_url(): void
    {
        $this->assertSame(url('/dashboard'), Tenant::url(null, '/dashboard'));
        $this->assertSame('https://realpropertyads.com/dashboard', Tenant::url('realpropertyads', '/dashboard'));
    }

    public function test_video_email_waits_for_the_whole_batch(): void
    {
        Mail::fake();
        Queue::fake();

        $customer = Customer::factory()->create();
        $user = User::factory()->create();
        $customer->users()->attach($user->id, ['role' => 'owner']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        $done = VideoCollateral::create([
            'campaign_id' => $campaign->id, 'platform' => 'Facebook Ads',
            'status' => 'completed', 's3_path' => 'x.mp4', 'is_active' => true,
        ]);
        $pending = VideoCollateral::create([
            'campaign_id' => $campaign->id, 'platform' => 'Facebook Ads',
            'status' => 'generating', 'is_active' => true,
        ]);

        (new VideoPostCompletion)($done);
        Mail::assertNothingSent();

        $pending->update(['status' => 'completed']);
        (new VideoPostCompletion)($pending->fresh());
        Mail::assertSent(\App\Mail\VideosGenerated::class, 1);
    }
}
