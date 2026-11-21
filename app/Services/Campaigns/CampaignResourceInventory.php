<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\Strategy;

final class CampaignResourceInventory
{
    /** @return list<array{platform:string,account_id:string,resource_id:string,resource_name:string,strategy_id:?int}> */
    public static function entries(Campaign $campaign): array
    {
        $campaign->loadMissing(['strategies', 'customer']);
        $entries = [];
        foreach (['google', 'facebook'] as $platform) {
            $google = $platform === 'google';
            $accountField = $google ? 'google_ads_customer_id' : 'facebook_ads_account_id';
            $account = self::account((string) $campaign->customer?->getAttribute($accountField), $google);
            if ($account === null) {
                continue;
            }
            $strategyField = $google ? 'google_ads_campaign_id' : 'facebook_campaign_id';
            $legacy = self::resource((string) $campaign->getAttribute($google ? $strategyField : 'facebook_ads_campaign_id'), $google, $account);
            $strategies = $campaign->strategies->sortBy('id')->filter(fn (Strategy $s) => self::platform($s) === $platform);
            $resources = [];
            $legacyBoundElsewhere = false;
            foreach ($strategies as $strategy) {
                $raw = (string) $strategy->getAttribute($strategyField);
                $id = self::resource($raw, $google, $account);
                $snapshot = $strategy->launch_intent['staged_accounts'][$accountField] ?? $strategy->launch_intent['accounts'][$accountField] ?? null;
                if ($snapshot && self::account((string) $snapshot, $google) !== $account) {
                    $legacyBoundElsewhere = $legacyBoundElsewhere || ($id !== null && $id === $legacy);

                    continue;
                }
                if ($id !== null) {
                    // A legacy resource claimed by two strategies has ambiguous ownership.
                    $resources[$id] = array_key_exists($id, $resources) ? null : (int) $strategy->id;
                }
            }
            if ($legacy !== null && ! $legacyBoundElsewhere && ! array_key_exists($legacy, $resources)) {
                $sole = $strategies->count() === 1 ? $strategies->first() : null;
                $bound = $sole?->launch_intent['staged_accounts'][$accountField] ?? $sole?->launch_intent['accounts'][$accountField] ?? null;
                if (! $bound || self::account((string) $bound, $google) === $account) {
                    $resources[$legacy] = $sole !== null && empty($sole->getAttribute($strategyField)) ? (int) $sole->id : null;
                }
            }
            foreach ($resources as $id => $strategyId) {
                $entries[] = [
                    'platform' => $platform,
                    'account_id' => $account,
                    'resource_id' => (string) $id,
                    'resource_name' => $google ? "customers/{$account}/campaigns/{$id}" : (string) $id,
                    'strategy_id' => $strategyId,
                ];
            }
        }

        return $entries;
    }

    /** A read-only view for existing one-platform provider adapters. */
    public static function view(Campaign $campaign, array $entry): Campaign
    {
        $view = clone $campaign;
        $strategy = $campaign->strategies->firstWhere('id', $entry['strategy_id']);
        $attributes = $campaign->getAttributes();
        foreach (['google_ads_campaign_id', 'facebook_ads_campaign_id', 'microsoft_ads_campaign_id', 'linkedin_campaign_id'] as $field) {
            $attributes[$field] = null;
        }
        $field = $entry['platform'] === 'google' ? 'google_ads_campaign_id' : 'facebook_ads_campaign_id';
        $attributes[$field] = $entry['resource_name'];
        if ($strategy?->daily_budget) {
            $attributes['daily_budget'] = $strategy->daily_budget;
            $attributes['approved_daily_budget'] = isset($strategy->launch_intent['daily_cents'])
                ? bcdiv((string) $strategy->launch_intent['daily_cents'], '100', 2) : $strategy->daily_budget;
        }
        $view->setRawAttributes($attributes, true);
        $view->setRelation('strategies', $campaign->strategies->where('id', $entry['strategy_id'])->values());

        return $view;
    }

    public static function platform(Strategy $strategy): ?string
    {
        $platform = \App\Services\DeploymentService::normalizePlatform((string) $strategy->platform);

        return in_array($platform, ['google', 'facebook'], true) ? $platform : null;
    }

    private static function account(string $raw, bool $google): ?string
    {
        $id = $google ? str_replace('-', '', $raw) : preg_replace('/^act_/', '', $raw);

        return $id !== null && ctype_digit($id) ? $id : null;
    }

    private static function resource(string $raw, bool $google, string $account): ?string
    {
        if (ctype_digit($raw)) {
            return $raw;
        }

        return $google && preg_match('~^customers/(\d+)/campaigns/(\d+)$~D', $raw, $parts) && $parts[1] === $account
            ? $parts[2] : null;
    }
}
