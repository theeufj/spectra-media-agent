<?php

namespace App\Services\MicrosoftAds;

use Illuminate\Support\Facades\Log;

class ImportService extends BaseMicrosoftAdsService
{
    /**
     * Import a Google Ads campaign into Microsoft Ads.
     * Microsoft Ads has a native import feature that mirrors Google campaigns.
     */
    public function importFromGoogleAds(string $googleAccountId): ?array
    {
        $result = $this->apiCall('AddImportJobs', [
            'ImportJobs' => ['ImportJob' => [[
                'Type' => 'GoogleImportJob',
                'Status' => 'New',
                'GoogleAccountId' => $googleAccountId,
                'Frequency' => ['Type' => 'RunOnce'],
                'ImportOptions' => [
                    'SearchAndReplaceUrl' => false,
                    'DeleteRemovedEntities' => true,
                    'EnableAutoBidding' => true,
                    'UpdateKeywordUrls' => false,
                    'UpdateAdGroupNetwork' => true,
                    'UpdateBids' => true,
                    'UpdateBudgets' => true,
                    'UpdateUrlOptions' => false,
                ],
            ]]],
        ]);

        if ($result) {
            Log::info('Microsoft Ads: Google import job created', ['google_account' => $googleAccountId]);
        }

        return $result;
    }

    /**
     * Check import job status.
     */
    public function getImportJobStatus(string $jobId): ?array
    {
        return $this->apiCall('GetImportJobsByIds', [
            'ImportJobIds' => ['long' => [$jobId]],
            'ImportType' => 'GoogleImportJob',
        ]);
    }
}
