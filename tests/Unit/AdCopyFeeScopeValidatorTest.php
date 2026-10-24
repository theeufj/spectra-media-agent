<?php

namespace Tests\Unit;

use App\Services\AdCopyFeeScopeValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdCopyFeeScopeValidatorTest extends TestCase
{
    private function evidence(string $content = 'The one-time campaign build costs $999. Ad spend is paid separately to the advertising platform.'): array
    {
        return ['landing_page' => 'https://agency.example/setup', 'sources' => [[
            'url' => 'https://agency.example/setup', 'content' => $content,
        ]]];
    }

    public static function claims(): array
    {
        return [
            'observed misleading coordination' => ['Ad spend goes directly to Google with no markup or recurring charges.', false],
            'unqualified standalone claim' => ['No recurring charges', false],
            'unqualified monthly claim' => ['Without monthly fees', false],
            'another agency qualifier cannot rescue a separate unqualified claim' => ['No agency fees. Ad spend goes to Google with no recurring charges.', false],
            'qualified agency fee' => ['No Recurring Agency Fees', true],
            'advertising modifies the agency service' => ['No recurring advertising agency fees', true],
            'ad modifies the management service' => ['No ongoing ad management fees', true],
            'agency fee and external spend are different objects' => ['No recurring agency fees or ad spend', false],
            'management and platform charges are different objects' => ['No recurring management fees or platform charges', false],
            'external scope before agency qualifier is not waived' => ['No recurring platform or agency fees', false],
            'ad spend before agency qualifier is not waived' => ['No recurring ad spend or agency fees', false],
            'unrelated positive cost cannot rescue waived media spend' => ['No recurring agency fees or ad spend, but hosting fees are paid separately.', false],
            'positive separate charge immediately qualifies its own object' => ['No recurring agency fees and ad spend is paid separately.', true],
            'agency qualifier before recurrence' => ['No agency recurring fees', true],
            'service qualifier after fee' => ['No recurring fees for our setup service', true],
            'mixed scope is not excused by a service qualifier' => ['No recurring fees for our setup service or ad spend', false],
            'qualified management fee' => ['Ad spend is separate. No ongoing management fees.', true],
            'no retainer' => ['No ongoing retainer. Pay the platform for your ads.', true],
            'positive separate media cost' => ['Ad spend is paid separately to the advertising platform.', true],
            'waived agency fee contrasted with positive platform charges' => ['No agency fees, but Google bills recurring charges.', true],
        ];
    }

    #[DataProvider('claims')]
    public function test_fee_claims_are_scoped_to_real_separate_costs(string $claim, bool $valid): void
    {
        $result = (new AdCopyFeeScopeValidator)->validate([$claim], [], $this->evidence());
        $this->assertSame($valid, $result['is_valid']);
        if (! $valid) {
            $this->assertSame('fee_scope_misleading_recurring_claim', $result['issues'][0]['code']);
            $this->assertSame('headlines', $result['issues'][0]['field']);
        }
    }

    public function test_a_separate_asset_cannot_rescue_an_unqualified_fee_claim(): void
    {
        $result = (new AdCopyFeeScopeValidator)->validate(['No recurring charges'],
            ['Ad spend is paid separately to the advertising platform.'], $this->evidence());
        $this->assertFalse($result['is_valid']);
    }

    public function test_a_legitimate_one_time_offer_is_not_blocked_by_an_unrelated_pricing_page(): void
    {
        $evidence = $this->evidence('Buy the repair guide once. No recurring fees or subscriptions.');
        $evidence['sources'][] = ['url' => 'https://agency.example/advertising',
            'content' => 'Ad spend is paid separately to the advertising platform.'];
        $this->assertTrue((new AdCopyFeeScopeValidator)->validate(['No recurring fees'], [], $evidence)['is_valid']);
        $this->assertTrue((new AdCopyFeeScopeValidator)->validate(['No recurring fees'], [], [])['is_valid']);
    }

    public function test_other_separately_billed_ongoing_costs_require_the_same_fee_precision(): void
    {
        $evidence = $this->evidence('Website design is a one-time purchase. Ongoing hosting fees are paid separately to your hosting provider.');
        $this->assertFalse((new AdCopyFeeScopeValidator)->validate([], ['No recurring charges'], $evidence)['is_valid']);
        $this->assertTrue((new AdCopyFeeScopeValidator)->validate([], ['No recurring design service fees. Hosting is paid separately.'], $evidence)['is_valid']);
    }

    public function test_a_source_that_waives_external_fees_does_not_invent_an_ongoing_charge(): void
    {
        $evidence = $this->evidence('The guide is a one-time purchase. No ongoing hosting fees or external charges apply.');
        $this->assertTrue((new AdCopyFeeScopeValidator)->validate(['No recurring fees'], [], $evidence)['is_valid']);
    }
}
