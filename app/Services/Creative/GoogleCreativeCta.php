<?php

namespace App\Services\Creative;

use App\Models\Strategy;
use App\Services\GoogleAds\ReconcileCampaignConversionGoals;
use Google\Ads\GoogleAds\V26\Enums\CallToActionTypeEnum\CallToActionType;

/** English native button labels accepted by both responsive Display and Performance Max. */
final class GoogleCreativeCta
{
    private const TYPES = [
        'Learn More' => CallToActionType::LEARN_MORE,
        'Shop Now' => CallToActionType::SHOP_NOW,
        'Sign Up' => CallToActionType::SIGN_UP,
        'Contact Us' => CallToActionType::CONTACT_US,
        'Subscribe' => CallToActionType::SUBSCRIBE,
        'Book Now' => CallToActionType::BOOK_NOW,
        'Get Quote' => CallToActionType::GET_QUOTE,
        'Download' => CallToActionType::DOWNLOAD,
        'Apply Now' => CallToActionType::APPLY_NOW,
    ];

    public static function label(Strategy $strategy): string
    {
        $goal = $strategy->conversion_goals['primary_goal'] ?? '';
        $goal = is_string($goal) ? strtolower(trim(str_replace(['-', '_'], ' ', $goal))) : '';

        return match ($goal) {
            'paid subscription', 'paid subscriptions', 'subscribe' => 'Subscribe',
            'book appointment', 'appointment', 'booking' => 'Book Now',
            'request quote', 'get quote' => 'Get Quote',
            'download' => 'Download',
            'submit application', 'apply' => 'Apply Now',
            default => match (ReconcileCampaignConversionGoals::categoryFor($strategy)) {
                'SIGNUP' => 'Sign Up', 'PURCHASE' => 'Shop Now', 'SUBMIT_LEAD_FORM' => 'Contact Us',
                default => 'Learn More',
            },
        };
    }

    /** Earlier approvals used Google's automatic CTA; do not change them on retry. */
    public static function approvedLabel(Strategy $strategy): ?string
    {
        $label = $strategy->creative_approval['google_native_cta'] ?? null;
        if ($label === null) {
            return null;
        }
        $fresh = $strategy->fresh();
        if (! $fresh || ! is_string($label) || ! isset(self::TYPES[$label]) || $fresh->creative_approval !== $strategy->creative_approval
            || ! app(CreativeRelease::class)->isApproved($fresh)) {
            throw new \RuntimeException('The native Google button requires current creative approval.');
        }

        return $label;
    }

    public static function type(string $label): int
    {
        return self::TYPES[$label] ?? throw new \InvalidArgumentException('Unrecognized Google call to action.');
    }
}
