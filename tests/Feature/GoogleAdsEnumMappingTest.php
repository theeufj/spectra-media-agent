<?php

namespace Tests\Feature;

use App\Jobs\MonitorCampaignStatus;
use Google\Ads\GoogleAds\V22\Enums\CampaignPrimaryStatusEnum\CampaignPrimaryStatus;
use Google\Ads\GoogleAds\V22\Enums\CampaignPrimaryStatusReasonEnum\CampaignPrimaryStatusReason;
use Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus as GoogleCampaignStatus;
use Tests\TestCase;

/**
 * Google's enum integers must translate to Google's names.
 *
 * These were hand-written match() blocks, and the reason list was shifted
 * against the real enum: every one of its eleven entries was wrong. A campaign
 * Google reported as BUDGET_CONSTRAINED came back AD_GROUP_NOT_ELIGIBLE_SERVING,
 * a paused one came back removed, and twenty-seven of the forty reasons —
 * HAS_ADS_DISAPPROVED, NO_KEYWORDS, MISSING_LOCATION_TARGETING among them —
 * were not mapped at all. Those strings are shown to the customer on their own
 * dashboard as the reason their campaign is not running.
 *
 * Nothing about a wrong-but-plausible enum name looks wrong, which is why this
 * compares against the SDK's table for every value rather than spot-checking.
 */
class GoogleAdsEnumMappingTest extends TestCase
{
    // Pure translation — no database, no campaign, nothing to set up.
    private function map(string $method, int $value): string
    {
        $m = new \ReflectionMethod(MonitorCampaignStatus::class, $method);
        $m->setAccessible(true);

        return $m->invoke(new MonitorCampaignStatus, $value);
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function everyEnumValue(): array
    {
        $cases = [];

        $enums = [
            'mapStatus' => GoogleCampaignStatus::class,
            'mapPrimaryStatus' => CampaignPrimaryStatus::class,
            'mapPrimaryStatusReason' => CampaignPrimaryStatusReason::class,
        ];

        foreach ($enums as $method => $enum) {
            foreach ((new \ReflectionClass($enum))->getConstants() as $name => $value) {
                if (! is_int($value) || $value <= 1) {
                    continue;   // UNSPECIFIED and UNKNOWN both mean "not told"
                }

                $cases["{$method}({$value}) = {$name}"] = [$method, $value, $name];
            }
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('everyEnumValue')]
    public function test_every_enum_value_maps_to_its_own_name(string $method, int $value, string $expected): void
    {
        $this->assertSame($expected, $this->map($method, $value));
    }

    public function test_unspecified_and_unknown_both_read_as_unknown(): void
    {
        // Callers already treat 'UNKNOWN' as "Google did not tell us" — the
        // silent-status list that decides whether a change deserves a
        // notification is keyed on it.
        foreach ([0, 1] as $value) {
            $this->assertSame('UNKNOWN', $this->map('mapPrimaryStatus', $value));
        }
    }

    public function test_a_value_newer_than_the_sdk_is_unknown_rather_than_a_crash(): void
    {
        // Google adds enum values between SDK releases. name() throws on those;
        // an unmapped reason must not take down the status sweep.
        $this->assertSame('UNKNOWN', $this->map('mapPrimaryStatusReason', 9999));
    }
}
