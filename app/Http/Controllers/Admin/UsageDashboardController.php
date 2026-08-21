<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeatureUsageDaily;
use App\Services\Analytics\AccountReadiness;
use App\Services\Analytics\AdoptionMetrics;
use App\Services\Analytics\UsagePeriod;
use App\Services\Analytics\UsageSummary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin landing page: how customers actually use the platform.
 *
 * Replaces AdminController@index, which redirected to the user list — so the
 * first thing an admin saw was a table of rows, not a picture of the business.
 *
 * TAB IS A SERVER-SIDE CONCERN. Only the active tab's queries run. The
 * alternatives were both worse:
 *
 *  - Separate JSON endpoints per tab would each re-enter the `admin` middleware
 *    group (throttle + AdminMiddleware + RequireTwoFactor +
 *    ConfirmDestructiveAction + LogAdminActions), spreading authorization over
 *    four surfaces and spending four throttle hits per page view. It would also
 *    move tab and period into React state, breaking deep links and the back button.
 *  - Deferring all four tabs would not help: Inertia fetches every deferred
 *    group on mount, so every tab's queries would run on every load regardless
 *    of what is on screen. Defer makes the page paint sooner; it does not make
 *    the work go away.
 *
 * This route is also the post-2FA `redirect()->intended()` target
 * (Admin\TwoFactorController) and both admin nav links, so it must stay fast —
 * hence Cache::flexible in the services rather than plain remember().
 */
class UsageDashboardController extends Controller
{
    /** Deliberately an allowlist: an unknown ?tab= shows the dashboard, not a 500. */
    private const TABS = ['engagement', 'readiness', 'retention', 'accounts', 'economics'];

    private const DEFAULT_TAB = 'engagement';

    public function index(Request $request): Response
    {
        $tab = in_array($request->query('tab'), self::TABS, true)
            ? $request->query('tab')
            : self::DEFAULT_TAB;

        $period = UsagePeriod::fromRequest($request->query('period'));

        return Inertia::render('Admin/UsageDashboard', [
            'tab' => $tab,
            'tabs' => $this->tabs(),
            'period' => $period->toArray(),
            'periodOptions' => UsagePeriod::options(),

            // The strip above the tabs. Six indexed counts, eagerly loaded so
            // switching tabs never blanks the top of the page.
            'summary' => (new UsageSummary($period))->cards(),

            // What the numbers do and do not cover. Rendered as a visible note
            // rather than left implicit: "active" here means write actions
            // only, because read-only usage left no trace until
            // feature_usage_daily started recording.
            'coverage' => [
                'recording_since' => FeatureUsageDaily::recordingSince(),
                'activity_log_retention_days' => (int) config('activity.retention_days', 30),
            ],

            ...$this->propsFor($tab, $period),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function propsFor(string $tab, UsagePeriod $period): array
    {
        return match ($tab) {
            'engagement' => $this->engagement($period),
            'readiness' => ['readiness' => Inertia::defer(fn () => (new AccountReadiness)->report(), 'readiness')],
            // Phases 2 and 3. The tabs are listed but disabled in the UI rather
            // than hidden, so it is obvious what is coming and obvious that its
            // absence is intentional.
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function engagement(UsagePeriod $period): array
    {
        $metrics = new AdoptionMetrics($period);

        return [
            // Cheap and always needed on this tab.
            'funnel' => $metrics->funnel(),
            'statusDrift' => $metrics->statusDrift(),

            // Deferred: these two are the slower half of the tab, and the funnel
            // above is what the page is actually about. Painting the funnel
            // immediately and filling these in is the right trade.
            'timeToValue' => Inertia::defer(fn () => $metrics->timeToValue(), 'adoption'),
            'featureBreadth' => Inertia::defer(fn () => $metrics->featureBreadth(), 'adoption'),
            'breadthHistogram' => Inertia::defer(fn () => $metrics->breadthHistogram(), 'adoption'),
        ];
    }

    /**
     * @return list<array{key: string, label: string, enabled: bool}>
     */
    private function tabs(): array
    {
        return [
            ['key' => 'engagement', 'label' => 'Engagement', 'enabled' => true],
            ['key' => 'readiness', 'label' => 'Readiness', 'enabled' => true],
            ['key' => 'retention', 'label' => 'Retention', 'enabled' => false],
            ['key' => 'accounts', 'label' => 'Accounts', 'enabled' => false],
            ['key' => 'economics', 'label' => 'Unit economics', 'enabled' => false],
        ];
    }
}
