<?php

namespace Tests\Unit\Enums;

use App\Enums\CampaignStatus;
use PHPUnit\Framework\TestCase;

class CampaignStatusTest extends TestCase
{
    public function test_legacy_uppercase_values_normalise(): void
    {
        // The column previously defaulted to 'DRAFT' and CheckCampaignPolicyViolations
        // wrote 'PAUSED'; both must resolve to the canonical lowercase cases.
        $this->assertSame(CampaignStatus::Draft, CampaignStatus::tryFromLoose('DRAFT'));
        $this->assertSame(CampaignStatus::Paused, CampaignStatus::tryFromLoose('PAUSED'));
        $this->assertSame(CampaignStatus::Active, CampaignStatus::tryFromLoose('Active'));
        $this->assertSame(CampaignStatus::Paused, CampaignStatus::tryFromLoose('  paused '));
    }

    public function test_unknown_and_empty_values_return_null(): void
    {
        $this->assertNull(CampaignStatus::tryFromLoose('not_a_status'));
        $this->assertNull(CampaignStatus::tryFromLoose(''));
        $this->assertNull(CampaignStatus::tryFromLoose(null));
    }

    public function test_paused_and_draft_are_non_delivering(): void
    {
        // This is the regression that mattered: CampaignHealthChecker compared a
        // 'PAUSED' status against lowercase 'paused', never matched, and alerted
        // on campaigns that were paused on purpose.
        $this->assertContains(CampaignStatus::Paused, CampaignStatus::nonDelivering());
        $this->assertContains(CampaignStatus::Draft, CampaignStatus::nonDelivering());
        $this->assertContains(CampaignStatus::PendingAdminDeployment, CampaignStatus::nonDelivering());
        $this->assertNotContains(CampaignStatus::Active, CampaignStatus::nonDelivering());
    }

    public function test_only_active_is_delivering(): void
    {
        $this->assertTrue(CampaignStatus::Active->isDelivering());

        foreach (CampaignStatus::nonDelivering() as $status) {
            $this->assertFalse($status->isDelivering(), "{$status->value} should not be delivering");
        }
    }

    public function test_every_case_has_a_label(): void
    {
        foreach (CampaignStatus::cases() as $case) {
            $this->assertNotSame('', $case->label());
        }
    }
}
