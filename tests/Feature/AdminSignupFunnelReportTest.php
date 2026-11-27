<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Analytics\SignupFunnelReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminSignupFunnelReportTest extends TestCase
{
    use DatabaseTransactions;

    private function journey(string $source = 'google', ?string $campaign = 'spectra_9', string $entry = '2026-11-15 12:00:00', ?string $surface = null): string
    {
        $id = (string) Str::uuid();
        DB::table('signup_funnel_journeys')->insert([
            'id' => $id, 'source' => $source, 'campaign_key' => $campaign, 'started_at' => CarbonImmutable::parse($entry, 'UTC')->toIso8601String(), 'entry_surface' => $surface,
        ]);

        return $id;
    }

    private function stages(string $id, array $stages, string $at = '2026-11-15 13:00:00', ?string $reason = null): void
    {
        foreach ($stages as $stage) {
            DB::table('signup_funnel_stages')->insert([
                'journey_id' => $id, 'stage' => $stage, 'reason' => $reason, 'occurred_at' => CarbonImmutable::parse($at, 'UTC')->toIso8601String(),
            ]);
        }
    }

    public function test_entry_filters_select_one_cohort_and_transitions_intersect_the_same_journeys(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20T14:00:00Z'));
        $preview = $this->journey();
        $this->stages($preview, ['landing_view', 'landing_visible', 'signup_cta_clicked', 'preview_started', 'preview_submitted', 'preview_succeeded', 'signup_viewed', 'signup_started', 'signup_submitted']);
        $this->stages($preview, ['signup_completed', 'email_verified'], '2026-11-17 16:00:00');

        $direct = $this->journey();
        $this->stages($direct, ['signup_viewed', 'signup_submitted', 'signup_completed']);
        $this->stages($direct, ['preview_succeeded'], '2026-11-16 12:00:00');
        $this->stages($direct, ['email_verified'], '2026-11-21 12:00:00');

        $unseen = $this->journey();
        $this->stages($unseen, ['landing_view']);
        $this->stages($unseen, ['preview_failed'], reason: 'private@example.com');
        $failed = $this->journey();
        $this->stages($failed, ['landing_view', 'landing_visible', 'signup_viewed', 'signup_started', 'signup_submitted']);
        $this->stages($failed, ['signup_failed'], reason: 'validation');
        $this->stages($preview, ['preview_failed'], reason: 'timeout');

        foreach ([
            $this->journey('meta'), $this->journey('google', 'spectra_10'), $this->journey('unattributed', null),
            $this->journey(entry: '2026-11-14 23:59:59'), $this->journey(entry: '2026-11-16 00:00:00'),
        ] as $outside) {
            $this->stages($outside, ['landing_view', 'signup_completed']);
        }

        $filters = ['funnel_source' => 'google', 'funnel_campaign' => 'spectra_9', 'funnel_from' => '2026-11-15', 'funnel_to' => '2026-11-15'];
        $report = app(SignupFunnelReport::class)->summary($filters);
        $this->assertSame(4, $report['journeys']);
        $this->assertSame(3, $report['steps']['landing_view']['reached']);
        $this->assertSame(2, $report['steps']['landing_visible']['reached']);
        $this->assertSame(2, $report['steps']['preview_succeeded']['reached']);
        $this->assertSame(1, $report['steps']['preview_succeeded']['both']);
        $this->assertSame([
            'reached' => 2, 'baseline' => 'signup_submitted', 'baseline_count' => 3,
            'both' => 2, 'missing' => 1, 'dropoff_percent' => 33.3,
        ], $report['steps']['signup_completed']);
        $this->assertSame(1, $report['steps']['email_verified']['reached']);
        $this->assertSame(1, $report['signups_with_preview']);
        $this->assertSame(1, $report['signups_without_observed_preview']);
        $this->assertSame([
            ['stage' => 'preview_failed', 'reason' => 'timeout', 'journeys' => 1],
            ['stage' => 'preview_failed', 'reason' => 'unknown', 'journeys' => 1],
            ['stage' => 'signup_failed', 'reason' => 'validation', 'journeys' => 1],
        ], $report['failures']);
        $this->assertStringNotContainsString('private@example.com', json_encode($report, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($direct, json_encode($report, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($preview, json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function test_first_measured_destination_scopes_every_stage_and_failure_in_the_filtered_campaign(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20T14:00:00Z'));
        $this->stages($this->journey(surface: 'home_preview'), ['landing_view', 'landing_visible']);
        $service = $this->journey(surface: 'google_ads_service');
        $this->stages($service, ['landing_view', 'signup_submitted', 'signup_completed']);
        $this->stages($service, ['preview_failed'], reason: 'timeout');
        $this->stages($this->journey(surface: 'register'), ['signup_viewed', 'signup_submitted', 'signup_completed']);
        $this->stages($this->journey(surface: null), ['signup_viewed']);
        $this->stages($this->journey(campaign: 'spectra_10', surface: 'google_ads_service'), ['landing_view']);

        $filters = ['funnel_source' => 'google', 'funnel_campaign' => 'spectra_9', 'funnel_from' => '2026-11-15', 'funnel_to' => '2026-11-15'];
        $serviceReport = app(SignupFunnelReport::class)->summary([...$filters, 'funnel_entry_surface' => 'google_ads_service']);
        $this->assertSame(1, $serviceReport['journeys']);
        $this->assertSame(1, $serviceReport['steps']['signup_completed']['reached']);
        $this->assertSame(1, $serviceReport['failures'][0]['journeys']);
        $home = app(SignupFunnelReport::class)->summary([...$filters, 'funnel_entry_surface' => 'home_preview']);
        $this->assertSame(1, $home['steps']['landing_visible']['reached']);
        $this->assertSame(0, $home['steps']['signup_completed']['reached']);
        $this->assertSame([], $home['failures']);
        $legacy = app(SignupFunnelReport::class)->summary([...$filters, 'funnel_entry_surface' => 'unspecified']);
        $this->assertSame(1, $legacy['journeys']);
        $this->assertSame(0, $legacy['steps']['signup_completed']['reached']);
        $this->assertSame(4, app(SignupFunnelReport::class)->summary($filters)['journeys']);
    }

    public function test_preview_path_is_nested_in_time_and_separates_direct_entry_and_later_payment(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20T14:00:00Z'));
        $newJourney = fn (string $surface) => $this->journey(campaign: 'spectra_820025', surface: $surface);
        $paid = $newJourney('home_preview');
        $pending = $newJourney('pricing');
        $outOfOrderPayment = $newJourney('home_preview');
        foreach ([$paid, $pending, $outOfOrderPayment] as $id) {
            $this->stages($id, ['landing_visible', 'preview_succeeded', 'save_clicked']);
            $this->stages($id, ['signup_completed'], '2026-11-16 13:00:00');
        }
        $this->stages($paid, ['paid_subscription'], '2026-11-19 13:00:00');
        $this->stages($pending, ['paid_subscription'], '2026-11-21 13:00:00');
        $this->stages($outOfOrderPayment, ['paid_subscription'], '2026-11-15 14:00:00');
        DB::table('signup_funnel_stages')->insertOrIgnore([
            'journey_id' => $paid, 'stage' => 'paid_subscription', 'occurred_at' => '2026-11-20T12:00:00+00:00',
        ]);

        $skippedSave = $newJourney('guides');
        $this->stages($skippedSave, ['landing_visible', 'preview_succeeded', 'signup_completed', 'paid_subscription']);
        $earlySave = $newJourney('google_ads_service');
        $this->stages($earlySave, ['landing_visible', 'save_clicked']);
        // Both browser signals arrived within the same timestamp second.
        $this->stages($earlySave, ['preview_succeeded', 'signup_completed', 'paid_subscription']);
        $earlyPreview = $newJourney('home_preview');
        $this->stages($earlyPreview, ['preview_succeeded']);
        $this->stages($earlyPreview, ['landing_visible', 'save_clicked', 'signup_completed', 'paid_subscription'], '2026-11-15 14:00:00');
        $this->stages($newJourney('register'), ['landing_visible', 'signup_viewed', 'signup_completed', 'paid_subscription']);
        $this->stages($newJourney('other_marketing'), ['landing_visible']);
        $this->stages($newJourney('pricing'), ['landing_view']);

        $filters = ['funnel_campaign' => 'spectra_820025', 'funnel_from' => '2026-11-15', 'funnel_to' => '2026-11-15'];
        $report = app(SignupFunnelReport::class)->summary($filters);
        $this->assertSame(9, $report['journeys']);
        $this->assertSame([8, 5, 3, 3, 1], array_column($report['preview_path'], 'reached'));
        $this->assertSame([null, 8, 5, 3, 3], array_column($report['preview_path'], 'previous'));
        $this->assertSame([null, 3, 2, 0, 2], array_column($report['preview_path'], 'missing'));
        $this->assertSame([null, 37.5, 40.0, 0.0, 66.7], array_column($report['preview_path'], 'dropoff_percent'));
        $this->assertSame(['registrations' => 4, 'paid' => 4], $report['other_paths']);
        $this->assertSame(['journeys' => 1, 'registrations' => 1, 'paid' => 1], $report['direct_entry']);

        $this->travelTo(CarbonImmutable::parse('2026-11-22T14:00:00Z'));
        $later = app(SignupFunnelReport::class)->summary($filters);
        $this->assertSame(2, $later['preview_path']['paid_subscription']['reached']);
        $this->assertSame(4, $later['other_paths']['paid']);
    }

    public function test_utc_entry_window_and_observation_cutoff_survive_a_local_database_timezone(): void
    {
        DB::statement("SET LOCAL TIME ZONE 'Australia/Sydney'");
        $this->travelTo(CarbonImmutable::parse('2026-11-20T00:05:00Z'));
        $this->journey(campaign: 'spectra_820026', entry: '2026-11-19T23:59:59Z');
        $id = $this->journey(campaign: 'spectra_820026', entry: '2026-11-20T00:00:01Z');
        $this->stages($id, ['landing_visible', 'preview_succeeded'], '2026-11-20T00:04:59Z');
        $this->stages($id, ['save_clicked'], '2026-11-20T00:05:01Z');

        $report = app(SignupFunnelReport::class)->summary([
            'funnel_from' => '2026-11-20', 'funnel_to' => '2026-11-20', 'funnel_campaign' => 'spectra_820026',
        ]);
        $this->assertSame(1, $report['journeys']);
        $this->assertSame([1, 1, 0, 0, 0], array_column($report['preview_path'], 'reached'));
    }

    public function test_admin_filter_is_validated_and_empty_intersections_do_not_imply_zero_percent_loss(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20T14:00:00Z'));
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));
        $this->actingAs($admin)->get(route('admin.conversions.index', ['funnel_source' => 'invalid', 'funnel_campaign' => 'raw-entry-value', 'funnel_entry_surface' => 'unknown-url']))
            ->assertSessionHasErrors(['funnel_source', 'funnel_campaign', 'funnel_entry_surface']);
        foreach (['pricing', 'other_marketing', 'guides'] as $surface) {
            $this->get(route('admin.conversions.index', ['funnel_entry_surface' => $surface]))->assertSuccessful()
                ->assertInertia(fn (Assert $page) => $page->where('signup_funnel.filters.funnel_entry_surface', $surface));
        }
        $this->get(route('admin.conversions.index', ['funnel_from' => '2026-11-18', 'funnel_to' => '2026-11-17']))
            ->assertSessionHasErrors('funnel_to');
        // An empty selected cohort can still have measurement history outside it.
        $this->journey(source: 'unattributed', campaign: null, entry: '2026-09-20 12:00:00');
        $earliest = CarbonImmutable::parse(DB::table('signup_funnel_journeys')->min('started_at'))->utc()->toIso8601String();
        $this->get(route('admin.conversions.index', ['funnel_source' => 'meta', 'funnel_campaign' => 'spectra_820022']))
            ->assertSuccessful()->assertInertia(fn (Assert $page) => $page->component('Admin/ConversionTracking')
            ->where('signup_funnel.journeys', 0)
            ->where('signup_funnel.first_observed_at', $earliest)
            ->where('signup_funnel.steps.signup_completed.dropoff_percent', null)
            ->where('signup_funnel.preview_path.landing_visible.reached', 0)
            ->where('signup_funnel.preview_path.paid_subscription.previous', 0)
            ->where('signup_funnel.preview_path.paid_subscription.dropoff_percent', null)
            ->where('signup_funnel.filters.funnel_source', 'meta')
            ->where('signup_funnel.filters.funnel_from', '2026-10-22')
            ->missing('signup_funnel.users')->missing('signup_funnel.events'));
    }
}
