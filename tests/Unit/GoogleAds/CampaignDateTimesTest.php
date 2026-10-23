<?php

namespace Tests\Unit\GoogleAds;

use App\Services\GoogleAds\Support\CampaignDateTimes;
use Google\Ads\GoogleAds\V26\Resources\Campaign;
use PHPUnit\Framework\TestCase;

class CampaignDateTimesTest extends TestCase
{
    public function test_day_schedules_serialize_to_v26_account_local_campaign_timestamps(): void
    {
        $campaign = new Campaign([
            'start_date_time' => CampaignDateTimes::startOfDay('20261004'),
            'end_date_time' => CampaignDateTimes::endOfDay('2026-10-31'),
        ]);
        $roundTrip = new Campaign;
        $roundTrip->mergeFromString($campaign->serializeToString());

        $this->assertSame('2026-10-04 00:00:00', $roundTrip->getStartDateTime());
        $this->assertSame('2026-10-31 23:59:59', $roundTrip->getEndDateTime());
        $this->assertSame('2026-10-31', CampaignDateTimes::calendarDate('2026-10-31 23:59:59'));
    }

    public function test_partial_boundary_days_are_excluded_from_day_only_forecasts(): void
    {
        $this->assertSame('2026-10-05', CampaignDateTimes::fullServingDay('2026-10-04 15:30:00', true));
        $this->assertSame('2026-10-30', CampaignDateTimes::fullServingDay('2026-10-31 15:30:00', false));
        $this->assertSame('2026-10-04', CampaignDateTimes::fullServingDay('2026-10-04 00:00:00', true));
        $this->assertSame('2026-10-31', CampaignDateTimes::fullServingDay('2026-10-31 23:59:59', false));
    }

    public function test_invalid_dates_and_times_remain_unknown(): void
    {
        foreach (['', '2026-02-30 00:00:00', '2026-10-31 24:00:00', '2026-10-31 23:60:00',
            '2026-10-31 23:59:60', '2026-10-31T23:59:59Z', '20261031 23:59:59', '2026-1031'] as $value) {
            $this->assertNull(CampaignDateTimes::calendarDate($value), $value);
        }
        $this->expectException(\InvalidArgumentException::class);
        CampaignDateTimes::endOfDay('2026-02-30');
    }
}
