<?php

use App\Models\NotificationTemplate;

/**
 * Catalog of admin-manageable notification emails (Phase 1: the CriticalAgentAlert family).
 *
 * The admin template manager lists these entries merged with any override rows in the
 * `notification_templates` table. `recipients` here is the CODE DEFAULT — it must match
 * the default passed at the call site; editing a template in the UI writes a DB row that
 * overrides it. `variables` are the {{placeholders}} available to admin-authored copy,
 * with sample values used for the live preview.
 *
 * Keys follow `critical_agent_alert.{alertType}`. alertTypes that are generated
 * dynamically (e.g. some CampaignRemediationAgent findings) are not listed and simply
 * fall back to the code default copy/recipients.
 */

$A = NotificationTemplate::RECIPIENTS_ADMINS;
$C = NotificationTemplate::RECIPIENTS_CUSTOMERS;

return [
    // ─── Account / automation health (admin-facing) ──────────────────────────
    'critical_agent_alert.agent_health' => [
        'category'    => 'Automation health',
        'label'       => 'Automation health issues',
        'description' => 'A scheduled optimization job has gone stale or is failing.',
        'recipients'  => $A,
        'variables'   => ['title' => 'Automation health: 2 issue(s) detected', 'message' => 'One or more optimization jobs are stale or failing: …'],
    ],
    'critical_agent_alert.health_check_summary' => [
        'category'    => 'Automation health',
        'label'       => 'Platform health summary (admin)',
        'description' => 'Six-hourly roll-up of critical/unhealthy accounts.',
        'recipients'  => $A,
        'variables'   => ['title' => 'Platform Health Alert', 'message' => 'Health check found 1 critical and 2 unhealthy accounts across 10 customers.'],
    ],
    'critical_agent_alert.google_ads_human_required' => [
        'category'    => 'Automation health',
        'label'       => 'Google Ads needs human review',
        'description' => 'An issue in a Google Ads account cannot be auto-fixed.',
        'recipients'  => $A,
        'variables'   => ['title' => 'Acme: Google Ads issue requires human review', 'message' => 'The following issue(s) cannot be fixed automatically…', 'customer_name' => 'Acme'],
    ],
    'critical_agent_alert.conversion_tracking' => [
        'category'    => 'Conversion tracking',
        'label'       => 'Conversion tracking broken / setup failed',
        'description' => 'Zero conversions in 30 days, or conversion-tracking setup failed. (Shared by the verify + setup jobs.)',
        'recipients'  => $A,
        'variables'   => ['title' => 'Conversion tracking may be broken: Acme', 'message' => '0 conversions recorded in last 30 days for Acme…', 'customer_id' => '8'],
    ],
    'critical_agent_alert.facebook' => [
        'category'    => 'Tokens & connections',
        'label'       => 'Facebook System User token invalid',
        'description' => 'The Facebook System User token is expired or invalid.',
        'recipients'  => $A,
        'variables'   => ['title' => 'Facebook System User token is invalid', 'message' => 'Facebook System User token is invalid: expired'],
    ],

    // ─── Spend / budget ──────────────────────────────────────────────────────
    'critical_agent_alert.spend_anomaly' => [
        'category'    => 'Spend & budget',
        'label'       => 'Unusual spend detected',
        'description' => 'A campaign is projected to spend well beyond its budget cap (runaway).',
        'recipients'  => $A,
        'variables'   => ['title' => 'Unusual spend detected for "Summer Sale"', 'message' => 'Campaign "Summer Sale" has spent $26.58 so far today and is projected to reach $63.79…', 'campaign_name' => 'Summer Sale', 'today_spend' => '26.58', 'projected_spend' => '63.79', 'daily_budget' => '40.00'],
    ],
    'critical_agent_alert.budget_exhaustion' => [
        'category'    => 'Spend & budget',
        'label'       => 'Budget exhausting early',
        'description' => 'A campaign has spent most of its daily budget before midday.',
        'recipients'  => $C,
        'variables'   => ['title' => 'Budget 85% spent by 11:00', 'message' => 'Campaign "Summer Sale" has spent $34 (85%) of its $40 daily budget by 11:00…', 'campaign_name' => 'Summer Sale'],
    ],
    'critical_agent_alert.budget_underpacing' => [
        'category'    => 'Spend & budget',
        'label'       => 'Budget underpacing',
        'description' => 'A campaign is well below its expected monthly spend.',
        'recipients'  => $C,
        'variables'   => ['title' => 'Budget Pacing Alert', 'message' => 'Campaign "Summer Sale" is only at 40% of expected monthly spend…', 'campaign_name' => 'Summer Sale', 'pacing_pct' => '40'],
    ],
    'critical_agent_alert.budget_overpacing' => [
        'category'    => 'Spend & budget',
        'label'       => 'Budget overpacing',
        'description' => 'A campaign will exhaust its monthly budget early.',
        'recipients'  => $C,
        'variables'   => ['title' => 'Budget Pacing Alert', 'message' => 'Campaign "Summer Sale" is at 160% of expected spend…', 'campaign_name' => 'Summer Sale', 'pacing_pct' => '160'],
    ],

    // ─── Performance ─────────────────────────────────────────────────────────
    'critical_agent_alert.performance_anomaly' => [
        'category'    => 'Performance',
        'label'       => 'Performance anomaly detected',
        'description' => 'CTR/CPC/CVR anomaly or zero delivery on a campaign.',
        'recipients'  => $A,
        'variables'   => ['title' => 'Performance Anomaly Detected', 'message' => 'CTR dropped 40% for "Summer Sale". …', 'campaign_name' => 'Summer Sale', 'anomaly_type' => 'ctr_drop'],
    ],
    'critical_agent_alert.conversion_drop' => [
        'category'    => 'Performance',
        'label'       => 'Conversions dropped',
        'description' => 'A campaign\'s conversions fell sharply vs the prior period.',
        'recipients'  => $C,
        'variables'   => ['title' => 'Conversions dropped 60% for "Summer Sale"', 'message' => 'Campaign "Summer Sale" had 4 conversions in the last 3 days vs 10 in the prior 3 days…', 'campaign_name' => 'Summer Sale'],
    ],
    'critical_agent_alert.health_check_critical' => [
        'category'    => 'Performance',
        'label'       => 'Platform health issue (customer)',
        'description' => 'A customer-facing platform health issue was detected.',
        'recipients'  => $C,
        'variables'   => ['title' => 'Platform Health Alert', 'message' => 'We detected an issue with your campaigns that requires attention.'],
    ],

    // ─── Keywords ────────────────────────────────────────────────────────────
    'critical_agent_alert.keyword_cannibalization' => [
        'category'    => 'Keywords',
        'label'       => 'Keyword cannibalization detected',
        'description' => 'Keywords appear in multiple ad groups (Google + Microsoft variants).',
        'recipients'  => $C,
        'variables'   => ['title' => 'Keyword Cannibalization Detected', 'message' => '3 keyword(s) are appearing in multiple ad groups…', 'count' => '3'],
    ],
    'critical_agent_alert.negative_keyword_conflict' => [
        'category'    => 'Keywords',
        'label'       => 'Negative keyword conflicts detected',
        'description' => 'Negative keywords are blocking active keywords (Google + Microsoft variants).',
        'recipients'  => $C,
        'variables'   => ['title' => 'Negative Keyword Conflicts Detected', 'message' => '2 negative keyword(s) are blocking active positive keywords…', 'count' => '2'],
    ],
    'critical_agent_alert.quality_score' => [
        'category'    => 'Keywords',
        'label'       => 'Low-QS keywords paused',
        'description' => 'Keywords paused after staying below a QS threshold.',
        'recipients'  => $A,
        'variables'   => ['title' => 'Low-QS Keywords Paused', 'message' => '3 keyword(s) were paused in "Summer Sale"…', 'campaign_name' => 'Summer Sale'],
    ],

    // ─── Auto-fixes (customer-facing; carry auto_resolved) ────────────────────
    'critical_agent_alert.audience_signals_added' => [
        'category'    => 'Auto-fixes',
        'label'       => 'Auto-fixed: audience signals added',
        'description' => 'PMax audience signals were auto-added.',
        'recipients'  => $C,
        'variables'   => ['title' => 'Auto-Fixed: Audience Signals Added to "Summer Sale"', 'message' => 'Your PMax campaign had no audience signals…', 'campaign_name' => 'Summer Sale'],
    ],
    'critical_agent_alert.creative_refresh' => [
        'category'    => 'Auto-fixes',
        'label'       => 'Auto-fixed: creative refresh',
        'description' => 'Fresh creative added to a zero-conversion campaign.',
        'recipients'  => $C,
        'variables'   => ['title' => 'Auto-Fixed: Creative Refresh on "Summer Sale"', 'message' => 'Your campaign spent $120 with no conversions…', 'campaign_name' => 'Summer Sale'],
    ],
    'critical_agent_alert.landing_page_fixed' => [
        'category'    => 'Auto-fixes',
        'label'       => 'Auto-fixed: landing page updated',
        'description' => 'Asset groups repointed to a conversion-focused URL.',
        'recipients'  => $C,
        'variables'   => ['title' => 'Auto-Fixed: Landing Page Updated on "Summer Sale"', 'message' => 'Your PMax campaign was sending paid traffic to an informational page…', 'campaign_name' => 'Summer Sale'],
    ],
    'critical_agent_alert.meta_creative_refreshed' => [
        'category'    => 'Auto-fixes',
        'label'       => 'Auto-fixed: Meta creative refreshed',
        'description' => 'Meta ad creative auto-refreshed for fatigue/starvation.',
        'recipients'  => $C,
        'variables'   => ['title' => 'Auto-Fixed: Meta Ad Creative Refreshed on "Summer Sale"', 'message' => 'We detected audience fatigue…', 'campaign_name' => 'Summer Sale'],
    ],
    'critical_agent_alert.compliance_failure' => [
        'category'    => 'Auto-fixes',
        'label'       => 'Campaign compliance check failed',
        'description' => 'A campaign cannot deploy due to compliance issues.',
        'recipients'  => $C,
        'variables'   => ['title' => 'Campaign Compliance Check Failed', 'message' => 'Your campaign "Summer Sale" cannot be deployed due to compliance issues.', 'campaign_name' => 'Summer Sale'],
    ],

    // ─── Facebook / Microsoft / LinkedIn platform alerts ─────────────────────
    'critical_agent_alert.facebook_learning' => [
        'category'    => 'Platform alerts',
        'label'       => 'Facebook learning phase issue',
        'description' => 'A Facebook campaign is stuck in / overlong in learning phase.',
        'recipients'  => $A,
        'variables'   => ['title' => 'Facebook Learning Phase Issue', 'message' => 'Campaign "Summer Sale" is stuck in Facebook LEARNING_LIMITED…', 'campaign_name' => 'Summer Sale'],
    ],
    'critical_agent_alert.facebook_relevance' => [
        'category'    => 'Platform alerts',
        'label'       => 'Low-relevance Facebook ads paused',
        'description' => 'Facebook ads paused for below-average relevance diagnostics.',
        'recipients'  => $A,
        'variables'   => ['title' => 'Low-Relevance Facebook Ads Paused', 'message' => '2 Facebook ad(s) were paused in "Summer Sale"…', 'campaign_name' => 'Summer Sale'],
    ],
    'critical_agent_alert.microsoft_budget_limited' => [
        'category'    => 'Platform alerts',
        'label'       => 'Microsoft Ads: budget capped',
        'description' => 'A Microsoft campaign is limited by its daily budget.',
        'recipients'  => $C,
        'variables'   => ['title' => 'Microsoft Ads: Budget Capped', 'message' => 'Campaign #123 is spending at 98% of its daily budget…'],
    ],
    'critical_agent_alert.microsoft_ctr_decline' => [
        'category'    => 'Platform alerts',
        'label'       => 'Microsoft Ads: CTR declining',
        'description' => 'A Microsoft campaign\'s CTR dropped week over week.',
        'recipients'  => $C,
        'variables'   => ['title' => 'Microsoft Ads: CTR Declining', 'message' => 'Campaign #123 CTR dropped 20% WoW…'],
    ],
    'critical_agent_alert.microsoft_cpl_above_benchmark' => [
        'category'    => 'Platform alerts',
        'label'       => 'Microsoft Ads: CPL above benchmark',
        'description' => 'A Microsoft campaign\'s cost-per-lead is well above benchmark.',
        'recipients'  => $C,
        'variables'   => ['title' => 'Microsoft Ads: CPL Above Benchmark', 'message' => 'Campaign #123 CPL $80 is 3x+ the benchmark…'],
    ],
    'critical_agent_alert.linkedin_frequency_fatigue' => [
        'category'    => 'Platform alerts',
        'label'       => 'LinkedIn: audience fatigue',
        'description' => 'A LinkedIn campaign\'s audience is saturated.',
        'recipients'  => $C,
        'variables'   => ['title' => 'LinkedIn Audience Fatigue', 'message' => 'Campaign #123 estimated frequency 6x in 30 days…'],
    ],
    'critical_agent_alert.linkedin_open_rate_drop' => [
        'category'    => 'Platform alerts',
        'label'       => 'LinkedIn: message ad decline',
        'description' => 'A LinkedIn message ad\'s performance dropped; campaign paused.',
        'recipients'  => $C,
        'variables'   => ['title' => 'LinkedIn Message Ad Decline', 'message' => 'Campaign #123 CTR dropped 30% WoW — campaign paused…'],
    ],
    'critical_agent_alert.linkedin_cpl_above_benchmark' => [
        'category'    => 'Platform alerts',
        'label'       => 'LinkedIn: CPL above benchmark',
        'description' => 'A LinkedIn campaign\'s cost-per-lead is well above benchmark.',
        'recipients'  => $C,
        'variables'   => ['title' => 'LinkedIn CPL Above Benchmark', 'message' => 'Campaign #123 CPL $150 is 3x+ the benchmark…'],
    ],
];
