<?php

namespace Tests\Feature;

use App\Services\MicrosoftAds\PerformanceService;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The search-terms report is submitted with `Aggregation => 'Summary'`, which
 * returns no TimePeriod column. `parseCsvContent()` accepted the header row and
 * then dropped every data row on a missing `timeperiod`, so
 * `SearchTermMiningAgent::mineMicrosoft()` has never mined a single term — and
 * an empty list there is indistinguishable from a clean account.
 */
class MicrosoftSearchTermsReportParsingTest extends TestCase
{
    private function parse(string $csv): array
    {
        // The parser is pure; the real constructor would authenticate.
        $service = (new ReflectionClass(PerformanceService::class))->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(PerformanceService::class, 'parseCsvContent');
        $method->setAccessible(true);

        return $method->invoke($service, $csv);
    }

    public function test_a_summary_report_without_a_time_period_column_still_parses(): void
    {
        $csv = <<<'CSV'
"Search query","Ad group ID","Campaign ID","Impressions","Clicks","Spend","Conversions","Ctr"
"emergency plumber near me","4001","3001","1,240","86","94.20","3","6.94%"
"cheap plumber","4001","3001","310","4","3.10","0","1.29%"
CSV;

        $rows = $this->parse($csv);

        $this->assertCount(2, $rows);
        $this->assertSame('emergency plumber near me', $rows[0]['searchquery']);
        $this->assertSame(1240, $rows[0]['impressions']);
        $this->assertSame(86, $rows[0]['clicks']);
        $this->assertSame(94.20, $rows[0]['cost']);
        $this->assertSame(6.94, $rows[0]['ctr']);

        // Nothing may invent a date for a report that carries none —
        // storePerformanceData() keys updateOrCreate on it.
        $this->assertArrayNotHasKey('date', $rows[0]);
    }

    public function test_a_daily_report_still_normalises_its_time_period_to_a_date(): void
    {
        $csv = <<<'CSV'
"TimePeriod","Campaign ID","Impressions","Clicks","Spend","Conversions","Revenue","Ctr","Average CPC","Cost per conversion"
"9/2/2026","3001","500","20","18.00","2","240.00","4.00%","0.90","9.00"
CSV;

        $rows = $this->parse($csv);

        $this->assertCount(1, $rows);
        $this->assertSame('2026-09-02', $rows[0]['date']);
        $this->assertSame(18.0, $rows[0]['cost']);
    }

    public function test_a_daily_row_with_an_empty_time_period_is_still_dropped(): void
    {
        $csv = <<<'CSV'
"TimePeriod","Campaign ID","Impressions","Clicks","Spend","Conversions","Revenue","Ctr","Average CPC","Cost per conversion"
"","3001","500","20","18.00","2","240.00","4.00%","0.90","9.00"
CSV;

        $this->assertSame([], $this->parse($csv));
    }
}
