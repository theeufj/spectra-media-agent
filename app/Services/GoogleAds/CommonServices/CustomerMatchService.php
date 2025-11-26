<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\Ads\GoogleAds\V22\Enums\CustomerMatchUploadKeyTypeEnum\CustomerMatchUploadKeyType;
use Google\Ads\GoogleAds\V22\Enums\OfflineUserDataJobTypeEnum\OfflineUserDataJobType;
use Google\Ads\GoogleAds\V22\Enums\OfflineUserDataJobStatusEnum\OfflineUserDataJobStatus;
use Google\Ads\GoogleAds\V22\Common\UserIdentifier;
use Google\Ads\GoogleAds\V22\Common\OfflineUserAddressInfo;
use Google\Ads\GoogleAds\V22\Resources\OfflineUserDataJob;
use Google\Ads\GoogleAds\V22\Resources\UserList;
use Google\Ads\GoogleAds\V22\Services\OfflineUserDataJobOperation;
use Google\Ads\GoogleAds\V22\Services\UserDataOperation;
use Google\Ads\GoogleAds\V22\Common\UserData;
use Illuminate\Support\Facades\Log;

/**
 * CustomerMatchService
 * 
 * Manages Customer Match audiences in Google Ads.
 * Enables uploading email lists for targeted advertising.
 */
class CustomerMatchService extends BaseGoogleAdsService
{
    /**
     * Create a new Customer Match user list.
     *
     * @param string $customerId
     * @param string $listName
     * @param string $description
     * @return string|null The user list resource name
     */
    public function createUserList(
        string $customerId,
        string $listName,
        string $description = ''
    ): ?string {
        $this->ensureClient();

        try {
            $userListServiceClient = $this->client->getUserListServiceClient();

            // Create user list
            $userList = new UserList([
                'name' => $listName,
                'description' => $description,
                'membership_status' => 1, // OPEN
                'membership_life_span' => 10000, // Maximum (unlimited)
                'crm_based_user_list' => [
                    'upload_key_type' => CustomerMatchUploadKeyType::CONTACT_INFO,
                    'data_source_type' => 1, // FIRST_PARTY
                ],
            ]);

            // Create the operation
            $userListOperation = new \Google\Ads\GoogleAds\V22\Services\UserListOperation();
            $userListOperation->setCreate($userList);

            // Execute
            $response = $userListServiceClient->mutateUserLists($customerId, [$userListOperation]);
            
            $userListResourceName = $response->getResults()[0]->getResourceName();

            Log::info('CustomerMatchService: User list created', [
                'customer_id' => $customerId,
                'list_name' => $listName,
                'resource_name' => $userListResourceName,
            ]);

            return $userListResourceName;

        } catch (GoogleAdsException $e) {
            Log::error('CustomerMatchService: Failed to create user list', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Upload customer emails to a Customer Match list.
     *
     * @param string $customerId
     * @param string $userListResourceName
     * @param array $emails Array of email addresses
     * @return array Upload result
     */
    public function uploadEmails(
        string $customerId,
        string $userListResourceName,
        array $emails
    ): array {
        $this->ensureClient();

        $result = [
            'success' => false,
            'uploaded' => 0,
            'failed' => 0,
            'job_resource_name' => null,
        ];

        try {
            $offlineUserDataJobServiceClient = $this->client->getOfflineUserDataJobServiceClient();

            // Create the offline user data job
            $offlineUserDataJob = new OfflineUserDataJob([
                'type' => OfflineUserDataJobType::CUSTOMER_MATCH_USER_LIST,
                'customer_match_user_list_metadata' => [
                    'user_list' => $userListResourceName,
                ],
            ]);

            // Create the job
            $createJobResponse = $offlineUserDataJobServiceClient->createOfflineUserDataJob(
                $customerId,
                $offlineUserDataJob
            );

            $jobResourceName = $createJobResponse->getResourceName();
            $result['job_resource_name'] = $jobResourceName;

            // Prepare user data operations
            $operations = [];
            foreach ($emails as $email) {
                $normalizedEmail = $this->normalizeEmail($email);
                if (!$normalizedEmail) {
                    $result['failed']++;
                    continue;
                }

                $userIdentifier = new UserIdentifier([
                    'hashed_email' => $this->hashEmail($normalizedEmail),
                ]);

                $userData = new UserData([
                    'user_identifiers' => [$userIdentifier],
                ]);

                $operation = new UserDataOperation();
                $operation->setCreate($userData);
                $operations[] = $operation;
                $result['uploaded']++;
            }

            // Add operations to the job (in batches if large)
            $batchSize = 10000;
            foreach (array_chunk($operations, $batchSize) as $batch) {
                $offlineUserDataJobServiceClient->addOfflineUserDataJobOperations(
                    $jobResourceName,
                    $batch
                );
            }

            // Run the job
            $offlineUserDataJobServiceClient->runOfflineUserDataJob($jobResourceName);

            $result['success'] = true;

            Log::info('CustomerMatchService: Email upload initiated', [
                'customer_id' => $customerId,
                'user_list' => $userListResourceName,
                'uploaded' => $result['uploaded'],
                'job' => $jobResourceName,
            ]);

        } catch (GoogleAdsException $e) {
            Log::error('CustomerMatchService: Upload failed', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Get the status of an offline user data job.
     */
    public function getJobStatus(string $customerId, string $jobResourceName): array
    {
        $this->ensureClient();

        try {
            $query = "SELECT " .
                     "offline_user_data_job.resource_name, " .
                     "offline_user_data_job.status, " .
                     "offline_user_data_job.failure_reason " .
                     "FROM offline_user_data_job " .
                     "WHERE offline_user_data_job.resource_name = '$jobResourceName'";

            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
            $stream = $googleAdsServiceClient->search($customerId, $query);

            foreach ($stream->iterateAllElements() as $googleAdsRow) {
                $job = $googleAdsRow->getOfflineUserDataJob();
                
                return [
                    'status' => $this->formatJobStatus($job->getStatus()),
                    'failure_reason' => $job->getFailureReason() ?: null,
                ];
            }

        } catch (GoogleAdsException $e) {
            Log::error('CustomerMatchService: Failed to get job status', [
                'error' => $e->getMessage(),
            ]);
        }

        return ['status' => 'unknown'];
    }

    /**
     * Get all Customer Match user lists for a customer.
     */
    public function getUserLists(string $customerId): array
    {
        $this->ensureClient();

        try {
            $query = "SELECT " .
                     "user_list.resource_name, " .
                     "user_list.name, " .
                     "user_list.description, " .
                     "user_list.membership_status, " .
                     "user_list.size_for_display, " .
                     "user_list.size_for_search " .
                     "FROM user_list " .
                     "WHERE user_list.type = 'CRM_BASED'";

            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
            $stream = $googleAdsServiceClient->search($customerId, $query);

            $lists = [];
            foreach ($stream->iterateAllElements() as $googleAdsRow) {
                $userList = $googleAdsRow->getUserList();
                
                $lists[] = [
                    'resource_name' => $userList->getResourceName(),
                    'name' => $userList->getName(),
                    'description' => $userList->getDescription(),
                    'size_display' => $userList->getSizeForDisplay(),
                    'size_search' => $userList->getSizeForSearch(),
                ];
            }

            return $lists;

        } catch (GoogleAdsException $e) {
            Log::error('CustomerMatchService: Failed to get user lists', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Normalize email for Customer Match.
     */
    protected function normalizeEmail(string $email): ?string
    {
        $email = trim(strtolower($email));
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        // Remove dots from Gmail addresses (before @)
        if (str_ends_with($email, '@gmail.com')) {
            [$local, $domain] = explode('@', $email);
            $local = str_replace('.', '', $local);
            $email = "{$local}@{$domain}";
        }

        return $email;
    }

    /**
     * Hash email using SHA256.
     */
    protected function hashEmail(string $email): string
    {
        return hash('sha256', $email);
    }

    /**
     * Format job status enum.
     */
    protected function formatJobStatus(int $status): string
    {
        return match ($status) {
            OfflineUserDataJobStatus::PENDING => 'pending',
            OfflineUserDataJobStatus::RUNNING => 'running',
            OfflineUserDataJobStatus::SUCCESS => 'success',
            OfflineUserDataJobStatus::FAILED => 'failed',
            default => 'unknown',
        };
    }
}
