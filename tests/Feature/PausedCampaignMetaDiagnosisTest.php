<?php

namespace Tests\Feature;

use App\Jobs\RunStrategicDiagnosis;
use App\Models\AdSpendCredit;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\CampaignDiagnosticsAgent;
use App\Services\Agents\CampaignRemediationAgent;
use App\Services\FacebookAds\CampaignService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PausedCampaignMetaDiagnosisTest extends TestCase
{
    use DatabaseTransactions;

    public function test_only_owner_paused_search_with_a_matching_active_meta_placement_receives_read_only_diagnostics(): void
    {
        $make = function (string $id) {
            $customer = Customer::factory()->create(['facebook_ads_account_id' => 'act_22'.$id, 'is_sandbox' => false]);
            $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'paused',
                'google_ads_campaign_id' => 'customers/33/campaigns/'.$id, 'facebook_ads_campaign_id' => $id]);
            $search = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads', 'campaign_type' => 'search',
                'deployment_status' => 'paused', 'execution_result' => ['metadata' => ['owner_paused_at' => now()->toIso8601String()]]]);
            $search->forceFill(['google_ads_campaign_id' => $campaign->google_ads_campaign_id])->save();
            $meta = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Facebook Ads', 'deployment_status' => 'active']);
            $meta->forceFill(['facebook_campaign_id' => $id])->save();

            return [$campaign, $search, $meta];
        };
        [$active, $search, $meta] = $make('100');
        [$held] = $make('101');
        $held->update(['spend_safety_hold' => ['reason' => 'owner_pause']]);
        [$billing] = $make('102');
        AdSpendCredit::factory()->create(['customer_id' => $billing->customer_id, 'campaigns_paused_at' => now()]);
        [, $wrongSearch] = $make('103');
        $wrongSearch->update(['execution_result' => []]);
        [, , $wrongMeta] = $make('104');
        $wrongMeta->forceFill(['facebook_campaign_id' => '200'])->save();
        $make('105');
        $make('106');

        $service = $this->createMock(CampaignService::class);
        $queried = [];
        $service->method('getCampaign')->willReturnCallback(function ($id) use (&$queried) {
            $queried[] = $id;

            return ['id' => $id, 'account_id' => $id === '106' ? '999' : '22'.$id, 'effective_status' => $id === '105' ? 'PAUSED' : 'ACTIVE'];
        });
        $this->app->bind(CampaignService::class, fn () => $service);
        $diagnostics = $this->createMock(CampaignDiagnosticsAgent::class);
        $diagnostics->expects($this->never())->method('diagnose');
        $diagnostics->expects($this->once())->method('diagnoseMeta')->with($this->callback(fn ($c) => $c->is($active)))
            ->willReturn([['type' => 'meta_pixel_missing', 'severity' => 'critical', 'can_auto_fix' => true]]);
        $remediator = $this->createMock(CampaignRemediationAgent::class);
        $remediator->expects($this->never())->method('remediate');

        (new RunStrategicDiagnosis)->handle($diagnostics, $remediator);

        $this->assertEqualsCanonicalizing(['100', '105', '106'], $queried);
        $result = AgentActivity::where('campaign_id', $active->id)->where('action', 'diagnosed')->sole();
        $this->assertSame('read_only', $result->details['mode']);
        $this->assertSame('meta_pixel_missing', $result->details['findings'][0]['type']);
        $this->assertSame('paused', $active->fresh()->status->value);
        $this->assertSame('paused', $search->fresh()->deployment_status);
        $this->assertSame('active', $meta->fresh()->deployment_status);
    }
}
