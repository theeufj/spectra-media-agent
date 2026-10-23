<?php

namespace App\Services\GoogleAds;

use App\Models\Strategy;
use App\Services\GoogleAds\CommonServices\CreateConversionAction;
use Google\Ads\GoogleAds\V26\Enums\ConversionActionCategoryEnum\ConversionActionCategory;
use Google\Ads\GoogleAds\V26\Enums\ConversionActionCountingTypeEnum\ConversionActionCountingType;

/** MCC-scoped website actions; an existing action is never reclassified or made primary. */
class WebsiteConversionActions extends BaseGoogleAdsService
{
    public const CATEGORIES = ['lead' => 'SUBMIT_LEAD_FORM', 'signup' => 'SIGNUP', 'purchase' => 'PURCHASE'];

    public function all(): array
    {
        $this->ensureClient();
        $id = $this->customer->cleanGoogleCustomerId();
        if (! ctype_digit($id)) {
            throw new \LogicException('A customer Google Ads account is required.');
        }
        $actions = [];
        $query = "SELECT conversion_action.resource_name, conversion_action.name, conversion_action.category, conversion_action.type, conversion_action.origin, conversion_action.status, conversion_action.primary_for_goal FROM conversion_action WHERE conversion_action.status = 'ENABLED' AND conversion_action.type = 'WEBPAGE'";
        foreach ($this->searchQuery($id, $query)->iterateAllElements() as $row) {
            $action = $row->getConversionAction();
            $category = ConversionActionCategory::name($action->getCategory());
            $objective = array_search($category, self::CATEGORIES, true);
            if ($objective === false || ! preg_match('#^customers/'.$id.'/conversionActions/\d+$#', $action->getResourceName())) {
                continue;
            }
            $actions[] = ['resource' => $action->getResourceName(), 'name' => $action->getName(),
                'objective' => $objective, 'category' => $category,
                'primary_for_goal' => $action->hasPrimaryForGoal() ? $action->getPrimaryForGoal() : null];
        }

        return $actions;
    }

    public function createFor(Strategy $strategy, string $objective, ?callable $authorize = null): array
    {
        $category = self::CATEGORIES[$objective] ?? throw new \InvalidArgumentException('Unsupported conversion objective.');
        $name = 'Site to Spend '.$strategy->uuid.' '.$objective;
        $creator = app(CreateConversionAction::class, ['customer' => $this->customer]);
        $resource = $creator($this->customer->cleanGoogleCustomerId(), $name, ConversionActionCategory::value($category), [
            'primary_for_goal' => false,
            'counting_type' => $objective === 'purchase' ? ConversionActionCountingType::MANY_PER_CLICK : ConversionActionCountingType::ONE_PER_CLICK,
            'default_value' => 0,
            'default_currency_code' => $this->customer->currency_code,
            'always_use_default_value' => false,
            'before_write' => $authorize,
        ]);
        $action = collect($this->all())->firstWhere('resource', $resource);
        if (! $action || $action['objective'] !== $objective || $action['primary_for_goal'] !== false) {
            throw new \RuntimeException('The new website action category and secondary status could not be verified. Existing conversion settings were left unchanged.');
        }

        return $action;
    }
}
