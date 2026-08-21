<?php

namespace App\Enums;

/**
 * The product surfaces whose adoption the admin Usage & Adoption dashboard reports on.
 *
 * Two distinct kinds of feature live here, and the difference decides where the
 * numbers come from:
 *
 *  - DERIVED — using the feature already writes a row somewhere (a seo_audits
 *    record, a creative_usages counter). Adoption is a COUNT(DISTINCT customer_id)
 *    against that table, and full history is available immediately.
 *  - RECORDED — the feature is read-only, or its only trace is destroyed. Viewing
 *    the analytics dashboard writes nothing; campaign_conversations keeps just the
 *    last 20 messages. These are only measurable via feature_usage_daily, and only
 *    from the day instrumentation lands. Panels must be labelled "since <date>"
 *    rather than rendering zeros for periods that predate the table.
 *
 * cases() drives the adoption chart, so a feature added here without a source is a
 * loud gap rather than a silent omission. Never pass bare strings to
 * FeatureRecorder — string keys drifting by casing is exactly what cost this
 * codebase real money on campaigns.status (see CampaignStatus and the
 * 2026_08_07_000000_normalise_campaign_status_casing migration).
 */
enum ProductFeature: string
{
    case Dashboard = 'dashboard';
    case Analytics = 'analytics';
    case AnalyticsCrossPlatform = 'analytics_cross_platform';
    case Attribution = 'attribution';
    case Roi = 'roi';
    case WarRoom = 'war_room';
    case Seo = 'seo';
    case Cro = 'cro';
    case Reports = 'reports';
    case Creatives = 'creatives';
    case Copilot = 'copilot';
    case ProductFeeds = 'product_feeds';
    case Proposals = 'proposals';
    case KnowledgeBase = 'knowledge_base';
    case Personas = 'personas';
    case Brand = 'brand';
    case AbTests = 'ab_tests';
    case Recommendations = 'recommendations';
    case Team = 'team';

    /**
     * Human-facing label for the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Dashboard',
            self::Analytics => 'Analytics',
            self::AnalyticsCrossPlatform => 'Cross-platform analytics',
            self::Attribution => 'Attribution',
            self::Roi => 'ROI dashboard',
            self::WarRoom => 'War room',
            self::Seo => 'SEO',
            self::Cro => 'CRO audits',
            self::Reports => 'Reports',
            self::Creatives => 'Creative generation',
            self::Copilot => 'Campaign copilot',
            self::ProductFeeds => 'Product feeds',
            self::Proposals => 'Proposals',
            self::KnowledgeBase => 'Knowledge base',
            self::Personas => 'Personas',
            self::Brand => 'Brand guidelines',
            self::AbTests => 'A/B tests',
            self::Recommendations => 'Recommendations',
            self::Team => 'Team & invitations',
        };
    }

    /**
     * Features whose adoption can be counted from tables that already exist.
     *
     * Everything not listed here is read-only or self-truncating, and shows no
     * history before feature_usage_daily started recording.
     */
    public static function derivable(): array
    {
        return [
            self::Seo,
            self::Cro,
            self::Creatives,
            self::ProductFeeds,
            self::Proposals,
            self::KnowledgeBase,
            self::Personas,
            self::Brand,
            self::AbTests,
            self::Recommendations,
            self::Team,
        ];
    }

    public function isDerivable(): bool
    {
        return in_array($this, self::derivable(), true);
    }

    /**
     * Resolve a value of unknown casing, returning null if unrecognised.
     * Mirrors CampaignStatus::tryFromLoose().
     */
    public static function tryFromLoose(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }
}
