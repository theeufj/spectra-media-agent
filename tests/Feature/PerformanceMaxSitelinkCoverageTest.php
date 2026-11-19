<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\Google\AdExtensionBuilder;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\CreateSitelinkAsset;
use App\Services\GoogleAds\CommonServices\LinkCampaignAsset;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use App\Services\GoogleAds\CommonServices\SitelinkCoverage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PerformanceMaxSitelinkCoverageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_staged_retries_fill_six_distinct_destinations_and_only_claim_acknowledged_links(): void
    {
        $customer = Customer::factory()->create(['website' => 'https://example.com', 'phone' => null]);
        $strategy = new Strategy(['campaign_type' => 'performance_max', 'bidding_strategy' => ['landing_page_url' => 'https://example.com/register']]);
        foreach ([
            'pricing' => 'Plans',
            'features' => 'Features',
            'services' => 'Services',
            'demo' => 'Demo',
            'about' => 'Features',
            'how-it-works' => 'How It Works — Review Your Account',
            'ad-management' => 'AI Ads Management & Campaign Reporting for Teams',
            'register' => 'Get Started',
            'terms-of-service' => 'Terms of Service',
            'support/tickets' => 'Help Desk',
            'blog/guide' => 'ROAS Explained: Revenue, Spend and Sales',
        ] as $path => $title) {
            $customer->pages()->create(['url' => $customer->website.'/'.$path, 'title' => $title, 'content' => $title]);
        }
        $existing = [];
        foreach (['pricing' => 'Plans', 'features' => 'Features', 'services' => 'Services', 'demo' => 'Demo'] as $path => $label) {
            $existing[] = ['asset' => ['finalUrls' => [$customer->website.'/'.$path], 'sitelinkAsset' => ['linkText' => $label]]];
        }
        $existing[] = $existing[0];
        $planner = app(SitelinkCoverage::class);
        $plan = $planner->plan($customer, $strategy, $existing);
        $this->assertSame(4, $plan['existing']);
        $this->assertSame(2, $plan['needed']);
        $this->assertEqualsCanonicalizing(['How It Works', 'AI Ads Management'], array_column($plan['links'], 'text'));
        $all = $planner->plan($customer, $strategy, $existing, 6)['links'];
        $this->assertEqualsCanonicalizing(['How It Works', 'AI Ads Management', 'ROAS Explained'], array_column($all, 'text'));

        $reader = $this->createMock(ReadCampaignConfiguration::class);
        $reader->method('sitelinks')->willReturnCallback(function () use (&$existing) {
            return $existing;
        });
        $this->app->bind(ReadCampaignConfiguration::class, fn () => $reader);
        $creator = $this->createMock(CreateSitelinkAsset::class);
        $creator->expects($this->exactly(3))->method('__invoke')->willReturnCallback(fn ($id, $text) => 'assets/'.$text);
        $this->app->bind(CreateSitelinkAsset::class, fn () => $creator);
        $attempt = 0;
        $linker = $this->createMock(LinkCampaignAsset::class);
        $linker->expects($this->exactly(3))->method('__invoke')->willReturnCallback(function ($account, $campaign, $asset) use (&$attempt, &$existing, $plan) {
            if (++$attempt === 1) {
                return null;
            }
            $label = substr($asset, 7);
            $link = collect($plan['links'])->firstWhere('text', $label);
            $existing[] = ['asset' => ['finalUrls' => [$link['url']], 'sitelinkAsset' => ['linkText' => $label]]];

            return 'campaignAssets/'.$attempt;
        });
        $this->app->bind(LinkCampaignAsset::class, fn () => $linker);
        $builder = new class($customer, $this->createMock(GeminiService::class)) extends AdExtensionBuilder
        {
            protected function createLocationExtension(string $customerId, string $campaignResourceName, ExecutionResult $result): void {}
        };
        $first = ExecutionResult::success();
        $builder->createAndLinkAdExtensions('123', 'customers/123/campaigns/9', $strategy, $first);
        $this->assertContains('google_sitelink_unconfirmed', array_column($first->warnings, 'code'));
        $builder->createAndLinkAdExtensions('123', 'customers/123/campaigns/9', $strategy, ExecutionResult::success());
        $this->assertSame([], $planner->plan($customer, $strategy, $existing)['links']);
    }
}
