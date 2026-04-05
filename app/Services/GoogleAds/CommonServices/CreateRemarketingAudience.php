<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Common\FlexibleRuleOperandInfo;
use Google\Ads\GoogleAds\V22\Common\FlexibleRuleUserListInfo;
use Google\Ads\GoogleAds\V22\Common\RuleBasedUserListInfo;
use Google\Ads\GoogleAds\V22\Common\UserListRuleInfo;
use Google\Ads\GoogleAds\V22\Common\UserListRuleItemGroupInfo;
use Google\Ads\GoogleAds\V22\Common\UserListRuleItemInfo;
use Google\Ads\GoogleAds\V22\Common\UserListStringRuleItemInfo;
use Google\Ads\GoogleAds\V22\Enums\UserListFlexibleRuleOperatorEnum\UserListFlexibleRuleOperator;
use Google\Ads\GoogleAds\V22\Enums\UserListMembershipStatusEnum\UserListMembershipStatus;
use Google\Ads\GoogleAds\V22\Enums\UserListPrepopulationStatusEnum\UserListPrepopulationStatus;
use Google\Ads\GoogleAds\V22\Enums\UserListStringRuleItemOperatorEnum\UserListStringRuleItemOperator;
use Google\Ads\GoogleAds\V22\Resources\UserList;
use Google\Ads\GoogleAds\V22\Services\MutateUserListsRequest;
use Google\Ads\GoogleAds\V22\Services\UserListOperation;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;

class CreateRemarketingAudience extends BaseGoogleAdsService
{
    /**
     * Create a rule-based remarketing audience from website visitors.
     *
     * @param string $customerId
     * @param string $listName
     * @param string $urlContains URL substring to match (e.g. '/products', '/checkout')
     * @param int $membershipLifeSpanDays How long users stay in the list
     * @return string|null Resource name of the created UserList
     */
    public function __invoke(
        string $customerId,
        string $listName,
        string $urlContains,
        int $membershipLifeSpanDays = 90
    ): ?string {
        $this->ensureClient();

        // Build the URL rule: visited pages containing the given string
        $urlRuleItem = new UserListRuleItemInfo([
            'name' => 'url__',
            'string_rule_item' => new UserListStringRuleItemInfo([
                'operator' => UserListStringRuleItemOperator::CONTAINS,
                'value' => $urlContains,
            ]),
        ]);

        $ruleItemGroup = new UserListRuleItemGroupInfo([
            'rule_items' => [$urlRuleItem],
        ]);

        $ruleInfo = new UserListRuleInfo([
            'rule_item_groups' => [$ruleItemGroup],
        ]);

        $flexibleRule = new FlexibleRuleUserListInfo([
            'inclusive_rule_operator' => UserListFlexibleRuleOperator::PBAND,
            'inclusive_operands' => [
                new FlexibleRuleOperandInfo([
                    'rule' => $ruleInfo,
                    'lookback_window_days' => $membershipLifeSpanDays,
                ]),
            ],
        ]);

        $ruleBasedUserList = new RuleBasedUserListInfo([
            'prepopulation_status' => UserListPrepopulationStatus::REQUESTED,
            'flexible_rule_user_list' => $flexibleRule,
        ]);

        $userList = new UserList([
            'name' => $listName . ' - ' . now()->format('Y-m-d'),
            'description' => "Remarketing: visitors matching '{$urlContains}'",
            'membership_status' => UserListMembershipStatus::OPEN,
            'membership_life_span' => $membershipLifeSpanDays,
            'rule_based_user_list' => $ruleBasedUserList,
        ]);

        $operation = new UserListOperation();
        $operation->setCreate($userList);

        try {
            $response = $this->client->getUserListServiceClient()->mutateUserLists(new MutateUserListsRequest([
                'customer_id' => $customerId,
                'operations' => [$operation],
            ]));

            $resourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Created Remarketing Audience '{$listName}' (url contains '{$urlContains}'): {$resourceName}");
            return $resourceName;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError("Failed to create Remarketing Audience: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a remarketing audience for all site visitors.
     */
    public function allVisitors(string $customerId, string $listName, int $membershipLifeSpanDays = 30): ?string
    {
        return $this($customerId, $listName, '/', $membershipLifeSpanDays);
    }

    /**
     * Create a remarketing audience for converters (e.g. thank-you page visitors).
     */
    public function converters(string $customerId, string $listName, string $conversionPageUrl = '/thank-you', int $membershipLifeSpanDays = 180): ?string
    {
        return $this($customerId, $listName, $conversionPageUrl, $membershipLifeSpanDays);
    }
}
