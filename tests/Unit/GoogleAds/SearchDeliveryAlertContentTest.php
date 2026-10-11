<?php

namespace Tests\Unit\GoogleAds;

use App\Services\Agents\GoogleSearchDeliveryAlertContent;
use PHPUnit\Framework\TestCase;

class SearchDeliveryAlertContentTest extends TestCase
{
    private function state(): array
    {
        return ['status' => 'approval_required', 'blocked_reason' => 'approved_bounded_trial',
            'checked_at' => '2026-10-11T01:00:00Z', 'currency_code' => 'AUD',
            'verified_limits' => ['checked_at' => '2026-10-11T01:00:00Z', 'daily_budget_micros' => 10_000_000,
                'cpc_bid_ceiling_micros' => 3_000_000],
            'measurement' => ['complete_hours' => 17, 'impressions' => 0, 'clicks' => 0, 'cost_micros' => 0],
            'diagnosis' => ['issues' => [['code' => 'limited_keyword_demand', 'message' => 'Low estimated keyword demand.']],
                'proposal' => ['summary' => 'Old proposal must not become the whole email.']]];
    }

    public function test_failed_check_labels_old_results_and_omits_unverified_current_limits(): void
    {
        $state = [...$this->state(), 'status' => 'unavailable', 'blocked_reason' => 'search_delivery_check_unavailable',
            'errors' => [['code' => 'search_delivery_check_unavailable', 'message' => 'Fresh evidence unavailable.']]];
        $content = GoogleSearchDeliveryAlertContent::forState($state);
        $body = implode(' ', [$content['message'], ...$content['evidence_lines'], $content['action_required']]);
        $this->assertStringContainsString('Last available measured results: 0 impressions', $body);
        $this->assertStringContainsString('Last available reported ad spend: AUD 0.00', $body);
        $this->assertStringContainsString('verify the latest Google Ads status', $body);
        $this->assertStringNotContainsString('Daily budget at this check', $body);
        $this->assertStringNotContainsString('Maximum cost-per-click bid at this check', $body);
        $this->assertStringNotContainsString('Old proposal', $body);
        $this->assertSame($state['errors'], $content['issues']);
    }

    public function test_stale_limits_do_not_become_current_settings_and_unknown_currency_is_not_usd(): void
    {
        $state = $this->state();
        $state['currency_code'] = null;
        $state['verified_limits']['checked_at'] = '2026-10-10T01:00:00Z';
        $state['measurement']['cost_micros'] = 12_345_000;
        $content = GoogleSearchDeliveryAlertContent::forState($state);
        $body = implode(' ', $content['evidence_lines']);
        $this->assertStringContainsString('12.35 in ad account currency', $body);
        $this->assertStringNotContainsString('USD', $body);
        $this->assertStringNotContainsString('Daily budget at this check', $body);
        unset($state['checked_at'], $state['verified_limits']['checked_at']);
        $missingTimestamp = GoogleSearchDeliveryAlertContent::forState($state);
        $this->assertStringNotContainsString('Daily budget at this check', implode(' ', $missingTimestamp['evidence_lines']));
    }

    public function test_partial_repair_requires_live_readback_instead_of_proposing_more_additions(): void
    {
        $state = [...$this->state(), 'status' => 'needs_review', 'blocked_reason' => 'partial_repair_unresolved',
            'repair' => ['started_at' => '2026-10-11T00:00:00Z', 'errors' => [['message' => 'Partial write.']]]];
        $content = GoogleSearchDeliveryAlertContent::forState($state);
        $this->assertStringContainsString('repair was attempted', $content['message']);
        $this->assertStringContainsString('live keyword settings before another change', $content['action_required']);
        $this->assertStringContainsString('Some keyword changes could not be confirmed', implode(' ', $content['evidence_lines']));
    }

    public function test_unrestored_repair_reports_post_repair_results_instead_of_old_restart_traffic(): void
    {
        $state = [...$this->state(), 'status' => 'needs_review', 'blocked_reason' => 'repair_did_not_restore_search_traffic',
            'repair' => ['started_at' => '2026-10-10T00:00:00Z'],
            'measurement' => ['complete_hours' => 48, 'impressions' => 100, 'clicks' => 10, 'cost_micros' => 80_000_000],
            'verification' => ['complete_hours' => 24, 'impressions' => 0, 'clicks' => 0, 'cost_micros' => 0]];
        $content = GoogleSearchDeliveryAlertContent::forState($state);
        $body = implode(' ', $content['evidence_lines']);
        $this->assertStringContainsString('0 impressions and 0 clicks across 24 complete reporting hours', $body);
        $this->assertStringNotContainsString('100 impressions', $body);
        $this->assertStringContainsString('has not restored measured Google Search traffic', $body);
    }

    public function test_unknown_reason_never_leaks_an_internal_code_and_missing_metrics_do_not_become_zero(): void
    {
        $state = [...$this->state(), 'blocked_reason' => 'new_internal_repair_code',
            'measurement' => ['complete_hours' => 0, 'impressions' => null, 'clicks' => null, 'cost_micros' => 0]];
        $content = GoogleSearchDeliveryAlertContent::forState($state);
        $body = implode(' ', $content['evidence_lines']);
        $this->assertStringNotContainsString('new_internal_repair_code', $body);
        $this->assertStringNotContainsString('0 impressions', $body);
        $this->assertStringNotContainsString('Reported ad spend', $body);
    }
}
