<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Common\CrmBasedUserListInfo;
use Google\Ads\GoogleAds\V22\Enums\CustomerMatchUploadKeyTypeEnum\CustomerMatchUploadKeyType;
use Google\Ads\GoogleAds\V22\Enums\UserListMembershipStatusEnum\UserListMembershipStatus;
use Google\Ads\GoogleAds\V22\Resources\UserList;
use Google\Ads\GoogleAds\V22\Services\MutateUserListsRequest;
use Google\Ads\GoogleAds\V22\Services\UserListOperation;
use Google\Ads\GoogleAds\V22\Services\CreateOfflineUserDataJobRequest;
use Google\Ads\GoogleAds\V22\Services\AddOfflineUserDataJobOperationsRequest;
use Google\Ads\GoogleAds\V22\Services\OfflineUserDataJobOperation;
use Google\Ads\GoogleAds\V22\Common\UserData;
use Google\Ads\GoogleAds\V22\Common\UserIdentifier;
use Google\Ads\GoogleAds\V22\Common\OfflineUserAddressInfo;
use Google\Ads\GoogleAds\V22\Common\CustomerMatchUserListMetadata;
use Google\Ads\GoogleAds\V22\Resources\OfflineUserDataJob;
use Google\Ads\GoogleAds\V22\Enums\OfflineUserDataJobTypeEnum\OfflineUserDataJobType;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;

class CreateUserList extends BaseGoogleAdsService
{
    /**
     * Create a Customer Match user list for email/phone-based audience targeting.
     *
     * @param string $customerId
     * @param string $listName
     * @param string $description
     * @param int $membershipLifeSpanDays
     * @return string|null Resource name of the created UserList
     */
    public function __invoke(
        string $customerId,
        string $listName,
        string $description = '',
        int $membershipLifeSpanDays = 365
    ): ?string {
        $this->ensureClient();

        $crmUserList = new CrmBasedUserListInfo([
            'upload_key_type' => CustomerMatchUploadKeyType::CONTACT_INFO,
        ]);

        $userList = new UserList([
            'name' => $listName . ' - ' . now()->format('Y-m-d'),
            'description' => $description ?: "Customer match list created by Site to Spend",
            'membership_status' => UserListMembershipStatus::OPEN,
            'membership_life_span' => $membershipLifeSpanDays,
            'crm_based_user_list' => $crmUserList,
        ]);

        $operation = new UserListOperation();
        $operation->setCreate($userList);

        try {
            $response = $this->client->getUserListServiceClient()->mutateUserLists(new MutateUserListsRequest([
                'customer_id' => $customerId,
                'operations' => [$operation],
            ]));

            $resourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Created Customer Match UserList '{$listName}': {$resourceName}");
            return $resourceName;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError("Failed to create Customer Match UserList: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Upload hashed user data (emails/phones) to a Customer Match list.
     *
     * @param string $customerId
     * @param string $userListResourceName
     * @param array $users Array of ['email' => ..., 'phone' => ..., 'first_name' => ..., 'last_name' => ...]
     * @return bool
     */
    public function uploadUsers(string $customerId, string $userListResourceName, array $users): bool
    {
        $this->ensureClient();

        try {
            // Create offline user data job
            $job = new OfflineUserDataJob([
                'type' => OfflineUserDataJobType::CUSTOMER_MATCH_USER_LIST,
                'customer_match_user_list_metadata' => new CustomerMatchUserListMetadata([
                    'user_list' => $userListResourceName,
                ]),
            ]);

            $jobServiceClient = $this->client->getOfflineUserDataJobServiceClient();

            $createResponse = $jobServiceClient->createOfflineUserDataJob(new CreateOfflineUserDataJobRequest([
                'customer_id' => $customerId,
                'job' => $job,
            ]));

            $jobResourceName = $createResponse->getResourceName();

            // Build operations for each user
            $operations = [];
            foreach ($users as $user) {
                $identifiers = [];

                if (!empty($user['email'])) {
                    $identifiers[] = new UserIdentifier([
                        'hashed_email' => $this->normalizeAndHash($user['email']),
                    ]);
                }

                if (!empty($user['phone'])) {
                    $identifiers[] = new UserIdentifier([
                        'hashed_phone_number' => $this->normalizeAndHash($user['phone']),
                    ]);
                }

                if (!empty($user['first_name']) && !empty($user['last_name'])) {
                    $identifiers[] = new UserIdentifier([
                        'address_info' => new OfflineUserAddressInfo([
                            'hashed_first_name' => $this->normalizeAndHash($user['first_name']),
                            'hashed_last_name' => $this->normalizeAndHash($user['last_name']),
                        ]),
                    ]);
                }

                if (!empty($identifiers)) {
                    $operations[] = new OfflineUserDataJobOperation([
                        'create' => new UserData(['user_identifiers' => $identifiers]),
                    ]);
                }
            }

            if (empty($operations)) {
                $this->logError("No valid user identifiers to upload");
                return false;
            }

            // Upload in batches of 10000
            foreach (array_chunk($operations, 10000) as $batch) {
                $jobServiceClient->addOfflineUserDataJobOperations(new AddOfflineUserDataJobOperationsRequest([
                    'resource_name' => $jobResourceName,
                    'operations' => $batch,
                    'enable_partial_failure' => true,
                ]));
            }

            // Run the job
            $jobServiceClient->runOfflineUserDataJob($jobResourceName);

            $this->logInfo("Uploaded " . count($operations) . " users to Customer Match list. Job: {$jobResourceName}");
            return true;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError("Failed to upload users to Customer Match list: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Normalize and SHA-256 hash a value for Customer Match.
     */
    protected function normalizeAndHash(string $value): string
    {
        return hash('sha256', strtolower(trim($value)));
    }
}
