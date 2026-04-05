<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Common\LogicalUserListInfo;
use Google\Ads\GoogleAds\V22\Common\LogicalUserListOperandInfo;
use Google\Ads\GoogleAds\V22\Enums\UserListMembershipStatusEnum\UserListMembershipStatus;
use Google\Ads\GoogleAds\V22\Resources\UserList;
use Google\Ads\GoogleAds\V22\Services\MutateUserListsRequest;
use Google\Ads\GoogleAds\V22\Services\UserListOperation;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;

class CreateCombinedAudience extends BaseGoogleAdsService
{
    /**
     * Create a combined audience (logical AND/OR of existing user lists).
     *
     * This creates a "logical" user list that combines multiple audience segments.
     * Useful for targeting e.g. "all site visitors" AND "customer match list" intersections,
     * or "converters" OR "high-value visitors" unions.
     *
     * @param string $customerId
     * @param string $listName
     * @param array $userListResourceNames Array of existing UserList resource names to combine
     * @param string $description
     * @return string|null Resource name of the created combined UserList
     */
    public function __invoke(
        string $customerId,
        string $listName,
        array $userListResourceNames,
        string $description = ''
    ): ?string {
        $this->ensureClient();

        if (count($userListResourceNames) < 2) {
            $this->logError("CreateCombinedAudience requires at least 2 user lists to combine");
            return null;
        }

        // Build operands from the provided user list resource names
        $operands = [];
        foreach ($userListResourceNames as $resourceName) {
            $operands[] = new LogicalUserListOperandInfo([
                'user_list' => $resourceName,
            ]);
        }

        $logicalUserList = new LogicalUserListInfo([
            'rules' => [
                new \Google\Ads\GoogleAds\V22\Common\UserListLogicalRuleInfo([
                    'operator' => \Google\Ads\GoogleAds\V22\Enums\UserListLogicalRuleOperatorEnum\UserListLogicalRuleOperator::PBAND,
                    'rule_operands' => $operands,
                ]),
            ],
        ]);

        $userList = new UserList([
            'name' => $listName . ' - ' . now()->format('Y-m-d'),
            'description' => $description ?: "Combined audience of " . count($userListResourceNames) . " segments",
            'membership_status' => UserListMembershipStatus::OPEN,
            'logical_user_list' => $logicalUserList,
        ]);

        $operation = new UserListOperation();
        $operation->setCreate($userList);

        try {
            $response = $this->client->getUserListServiceClient()->mutateUserLists(new MutateUserListsRequest([
                'customer_id' => $customerId,
                'operations' => [$operation],
            ]));

            $resourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Created Combined Audience '{$listName}': {$resourceName}");
            return $resourceName;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError("Failed to create Combined Audience: " . $e->getMessage());
            return null;
        }
    }
}
