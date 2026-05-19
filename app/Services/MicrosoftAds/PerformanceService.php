<?php

namespace App\Services\MicrosoftAds;

use App\Models\Campaign;
use App\Models\MicrosoftAdsPerformanceData;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PerformanceService extends BaseMicrosoftAdsService
{
    private const POLL_ATTEMPTS = 12;
    private const POLL_WAIT_SECONDS = 5;

    /**
     * Fetch and store campaign performance data.
     * Full cycle: submit report → poll until ready → download CSV → parse → store.
     */
    public function syncPerformance(Campaign $campaign, int $days = 30): array
    {
        $campaignId = $campaign->microsoft_ads_campaign_id;
        if (!$campaignId) {
            return ['error' => 'No Microsoft Ads campaign ID'];
        }

        $startDate = now()->subDays($days)->format('Y-m-d');
        $endDate   = now()->format('Y-m-d');

        // Step 1: Submit the report request
        $submission = $this->reportingCallWithRetry('SubmitGenerateReport', [
            'ReportRequest' => [
                'Type'                  => 'CampaignPerformanceReportRequest',
                'Format'                => 'Csv',
                'Language'              => 'English',
                'ReportName'            => "Campaign Performance {$startDate} to {$endDate}",
                'ReturnOnlyCompleteData' => false,
                'ExcludeReportHeader'   => true,
                'ExcludeReportFooter'   => true,
                'Time' => [
                    'CustomDateRangeStart' => $this->formatDate($startDate),
                    'CustomDateRangeEnd'   => $this->formatDate($endDate),
                ],
                'Columns' => [
                    'CampaignPerformanceReportColumn' => [
                        'TimePeriod', 'CampaignId', 'Impressions', 'Clicks',
                        'Spend', 'Conversions', 'Revenue', 'Ctr', 'AverageCpc', 'CostPerConversion',
                    ],
                ],
                'Filter' => ['CampaignIds' => ['long' => [$campaignId]]],
                'Scope'  => [
                    'AccountIds' => ['long' => [$this->customer->microsoft_ads_account_id]],
                ],
                'Aggregation' => 'Daily',
            ],
        ]);

        $reportRequestId = $submission['ReportRequestId'] ?? null;
        if (!$reportRequestId) {
            Log::error('Microsoft Ads: Failed to submit performance report', ['campaign_id' => $campaign->id]);
            return ['error' => 'Report submission failed'];
        }

        Log::info('Microsoft Ads: Performance report submitted', [
            'campaign_id'      => $campaign->id,
            'report_request_id' => $reportRequestId,
        ]);

        // Step 2: Poll until the report is ready
        $downloadUrl = $this->pollForReportCompletion($reportRequestId);
        if (!$downloadUrl) {
            return ['error' => 'Report did not complete within polling window', 'report_request_id' => $reportRequestId];
        }

        // Step 3: Download and parse the CSV
        $rows = $this->downloadAndParseCsv($downloadUrl);
        if (empty($rows)) {
            Log::info('Microsoft Ads: No performance data returned', ['campaign_id' => $campaign->id]);
            return ['rows_stored' => 0];
        }

        // Step 4: Store
        $stored = $this->storePerformanceData($campaign, $rows);

        Log::info('Microsoft Ads: Performance data synced', [
            'campaign_id' => $campaign->id,
            'rows_stored'  => $stored,
        ]);

        return ['rows_stored' => $stored];
    }

    /**
     * Get search terms report for a campaign.
     * Returns rows mapped to a standard format compatible with Google's search terms output.
     */
    public function getSearchTerms(string $campaignId, int $days = 30): array
    {
        $startDate = now()->subDays($days)->format('Y-m-d');
        $endDate   = now()->format('Y-m-d');

        $submission = $this->reportingCallWithRetry('SubmitGenerateReport', [
            'ReportRequest' => [
                'Type'                  => 'SearchQueryPerformanceReportRequest',
                'Format'                => 'Csv',
                'Language'              => 'English',
                'ReportName'            => "Search Terms {$startDate} to {$endDate}",
                'ReturnOnlyCompleteData' => false,
                'ExcludeReportHeader'   => true,
                'ExcludeReportFooter'   => true,
                'Time' => [
                    'CustomDateRangeStart' => $this->formatDate($startDate),
                    'CustomDateRangeEnd'   => $this->formatDate($endDate),
                ],
                'Columns' => [
                    'SearchQueryPerformanceReportColumn' => [
                        'SearchQuery', 'AdGroupId', 'CampaignId',
                        'Impressions', 'Clicks', 'Spend', 'Conversions', 'Ctr',
                    ],
                ],
                'Filter'      => ['CampaignIds' => ['long' => [$campaignId]]],
                'Scope'       => ['AccountIds' => ['long' => [$this->customer->microsoft_ads_account_id]]],
                'Aggregation' => 'Summary',
            ],
        ]);

        $reportRequestId = $submission['ReportRequestId'] ?? null;
        if (!$reportRequestId) {
            Log::error('Microsoft Ads: Failed to submit search terms report', ['campaign_id' => $campaignId]);
            return [];
        }

        $downloadUrl = $this->pollForReportCompletion($reportRequestId);
        if (!$downloadUrl) {
            return [];
        }

        $rows = $this->downloadAndParseCsv($downloadUrl);

        // Normalise to the same shape Google's search term report returns
        return array_map(fn ($row) => [
            'search_term' => $row['searchquery'] ?? $row['search query'] ?? '',
            'impressions' => (int)   ($row['impressions'] ?? 0),
            'clicks'      => (int)   ($row['clicks']      ?? 0),
            'cost'        => (float) ($row['spend']        ?? 0),
            'conversions' => (float) ($row['conversions']  ?? 0),
            'ctr'         => (float) ($row['ctr']          ?? 0),
            'ad_group_id' => $row['adgroupid']  ?? $row['ad group id']  ?? '',
            'campaign_id' => $row['campaignid'] ?? $row['campaign id']  ?? '',
        ], $rows);
    }

    /**
     * Store parsed performance rows into the database.
     */
    public function storePerformanceData(Campaign $campaign, array $rows): int
    {
        $stored = 0;
        foreach ($rows as $row) {
            MicrosoftAdsPerformanceData::updateOrCreate(
                ['campaign_id' => $campaign->id, 'date' => $row['date']],
                [
                    'impressions'      => $row['impressions']      ?? 0,
                    'clicks'           => $row['clicks']           ?? 0,
                    'cost'             => $row['cost']             ?? 0,
                    'conversions'      => $row['conversions']      ?? 0,
                    'conversion_value' => $row['conversion_value'] ?? 0,
                    'ctr'              => $row['ctr']              ?? 0,
                    'cpc'              => $row['cpc']              ?? 0,
                    'cpa'              => $row['cpa']              ?? 0,
                ]
            );
            $stored++;
        }
        return $stored;
    }

    // ---- Private helpers ----

    /**
     * Poll PollGenerateReport until the report is Success or fails.
     * Returns the download URL on success, null otherwise.
     */
    private function pollForReportCompletion(string $reportRequestId): ?string
    {
        for ($attempt = 1; $attempt <= self::POLL_ATTEMPTS; $attempt++) {
            $result = $this->reportingCall('PollGenerateReport', [
                'ReportRequestId' => $reportRequestId,
            ]);

            $status      = $result['ReportRequestStatus']['Status'] ?? null;
            $downloadUrl = $result['ReportRequestStatus']['ReportDownloadUrl'] ?? null;

            Log::info("Microsoft Ads: Report poll {$attempt}/" . self::POLL_ATTEMPTS, [
                'report_request_id' => $reportRequestId,
                'status'            => $status,
            ]);

            if ($status === 'Success') {
                return $downloadUrl;
            }

            if ($status === 'Error' || $status === 'Expired') {
                Log::error('Microsoft Ads: Report generation failed', [
                    'status'            => $status,
                    'report_request_id' => $reportRequestId,
                ]);
                return null;
            }

            if ($attempt < self::POLL_ATTEMPTS) {
                sleep(self::POLL_WAIT_SECONDS);
            }
        }

        Log::warning('Microsoft Ads: Report polling timed out', ['report_request_id' => $reportRequestId]);
        return null;
    }

    /**
     * Download the report from the given URL and parse it into row arrays.
     * Microsoft may return a ZIP; we extract the first CSV inside.
     */
    private function downloadAndParseCsv(string $downloadUrl): array
    {
        try {
            $response = Http::timeout(60)->get($downloadUrl);

            if (!$response->successful()) {
                Log::error('Microsoft Ads: Report download failed', [
                    'status' => $response->status(),
                ]);
                return [];
            }

            $content = $response->body();

            // ZIP detection (PK magic bytes)
            if (str_starts_with($content, "PK\x03\x04")) {
                $content = $this->extractCsvFromZip($content);
                if (!$content) {
                    return [];
                }
            }

            return $this->parseCsvContent($content);
        } catch (\Exception $e) {
            Log::error('Microsoft Ads: Report download exception', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function extractCsvFromZip(string $zipContent): ?string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'msads_') . '.zip';
        file_put_contents($tmpPath, $zipContent);

        try {
            $zip = new \ZipArchive();
            if ($zip->open($tmpPath) !== true) {
                return null;
            }

            $csv = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (str_ends_with($zip->getNameIndex($i), '.csv')) {
                    $csv = $zip->getFromIndex($i);
                    break;
                }
            }

            $zip->close();
            return $csv;
        } finally {
            if (file_exists($tmpPath) && !unlink($tmpPath)) {
                Log::warning('Microsoft Ads: Failed to delete temp zip', ['path' => $tmpPath]);
            }
        }
    }

    /**
     * Parse a Microsoft Ads CSV report body into normalised row arrays.
     * Handles both with-header and headerless (ExcludeReportHeader=true) formats.
     */
    private function parseCsvContent(string $content): array
    {
        $rows    = [];
        $headers = null;

        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '' || $line === '@') {
                continue;
            }

            $cols = str_getcsv($line);

            if ($headers === null) {
                $normalized = array_map(fn ($h) => strtolower(str_replace([' ', '-'], '', trim($h, '"'))), $cols);
                // Validate this is the header row by checking for a known column
                if (in_array('timeperiod', $normalized) || in_array('searchquery', $normalized)) {
                    $headers = $normalized;
                }
                // If not a header row, skip (report meta lines)
                continue;
            }

            if (count($cols) !== count($headers)) {
                continue;
            }

            $row = array_combine($headers, array_map(fn ($v) => trim($v, '"'), $cols));

            // Campaign performance rows have a date in TimePeriod (YYYY-MM-DD or MM/DD/YYYY)
            $rawDate = $row['timeperiod'] ?? '';
            if (!$rawDate) {
                continue;
            }

            // Normalise date to Y-m-d
            try {
                $date = Carbon::parse($rawDate)->format('Y-m-d');
            } catch (\Exception) {
                continue;
            }

            $rows[] = [
                'date'             => $date,
                'impressions'      => (int)   str_replace(',', '', $row['impressions']        ?? 0),
                'clicks'           => (int)   str_replace(',', '', $row['clicks']             ?? 0),
                'cost'             => (float) str_replace(',', '', $row['spend']              ?? 0),
                'conversions'      => (float) str_replace(',', '', $row['conversions']        ?? 0),
                'conversion_value' => (float) str_replace(',', '', $row['revenue']            ?? 0),
                'ctr'              => (float) str_replace(['%', ','], '', $row['ctr']         ?? 0),
                'cpc'              => (float) str_replace(',', '', $row['averagecpc']         ?? 0),
                'cpa'              => (float) str_replace(',', '', $row['costperconversion']  ?? 0),
            ] + $row; // keep raw row for search-terms callers that need extra cols
        }

        return $rows;
    }

    protected function formatDate(string $date): array
    {
        $d = Carbon::parse($date);
        return ['Day' => $d->day, 'Month' => $d->month, 'Year' => $d->year];
    }
}
