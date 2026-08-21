<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UsageDashboardTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private User $support;

    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();

        $adminRole = Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin']));
        $supportRole = Role::unguarded(fn () => Role::firstOrCreate(['name' => 'support']));

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->support = User::factory()->create();
        $this->support->roles()->attach($supportRole);

        $this->regularUser = User::factory()->create();
    }

    // ── Access ───────────────────────────────────────────────────────────────

    public function test_an_admin_sees_the_usage_dashboard(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->component('Admin/UsageDashboard'));
    }

    public function test_admin_dashboard_renders_rather_than_redirecting(): void
    {
        // Regression guard on the repoint away from AdminController@index,
        // which redirected to the user list. Admin\TwoFactorController sends
        // people here with redirect()->intended() after a 2FA challenge, and
        // both nav links in AuthenticatedLayout point at it — a redirect chain
        // here is felt on every admin login.
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertStatus(200);
    }

    public function test_support_staff_can_read_the_dashboard(): void
    {
        // AdminMiddleware splits access by HTTP verb: canAccessAdmin() for
        // reads, isFullAdmin() for writes. Support must keep GET access, and
        // that is easy to break without noticing.
        $this->actingAs($this->support)
            ->get(route('admin.dashboard'))
            ->assertStatus(200);
    }

    public function test_a_regular_user_is_refused(): void
    {
        $this->actingAs($this->regularUser)
            ->get(route('admin.dashboard'))
            ->assertStatus(403);
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    // ── Input handling ───────────────────────────────────────────────────────

    public function test_an_unknown_tab_falls_back_to_the_default(): void
    {
        // A bad query string should show the dashboard, not a 500 — this URL
        // arrives from stale bookmarks and hand-edited links.
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard', ['tab' => 'nonsense']))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->where('tab', 'engagement'));
    }

    public function test_an_unknown_period_falls_back_to_thirty_days(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard', ['period' => '9999']))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->where('period.key', '30'));
    }

    public function test_a_valid_period_is_honoured(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard', ['period' => '7']))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->where('period.key', '7')->where('period.days', 7));
    }

    public function test_month_to_date_is_a_valid_period(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard', ['period' => 'mtd']))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->where('period.key', 'mtd'));
    }

    // ── Shape ────────────────────────────────────────────────────────────────

    public function test_the_readiness_tab_is_reachable(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard', ['tab' => 'readiness']))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->where('tab', 'readiness'));
    }

    public function test_the_readiness_tab_is_advertised_as_enabled(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertInertia(fn ($page) => $page->where(
                'tabs',
                fn ($tabs) => collect($tabs)->firstWhere('key', 'readiness')['enabled'] === true,
            ));
    }

    public function test_the_readiness_data_loads_on_a_deferred_reload(): void
    {
        // The tab renders a Deferred block, so the initial response carries no
        // readiness prop — it arrives on a follow-up partial request. If that
        // request fails the tab sits on its loading message forever, which is
        // indistinguishable from the tab not working.
        $version = (new \App\Http\Middleware\HandleInertiaRequests)->version(request());

        $this->actingAs($this->admin)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) $version,
                'X-Inertia-Partial-Component' => 'Admin/UsageDashboard',
                'X-Inertia-Partial-Data' => 'readiness',
            ])
            ->get(route('admin.dashboard', ['tab' => 'readiness']))
            ->assertStatus(200)
            ->assertJsonStructure([
                'props' => ['readiness' => ['accounts', 'summary', 'blocker_counts']],
            ]);
    }

    public function test_the_summary_strip_and_coverage_note_are_always_present(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertInertia(fn ($page) => $page
                ->has('summary', 6)
                ->has('coverage.recording_since')
                ->has('coverage.activity_log_retention_days')
                ->has('funnel', 6)
                ->has('statusDrift')
            );
    }

    public function test_the_heavy_adoption_props_are_deferred(): void
    {
        // They must NOT be in the initial payload — that is the whole point of
        // deferring them. Inertia advertises them under deferredProps instead.
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertInertia(fn ($page) => $page
                ->missing('featureBreadth')
                ->missing('breadthHistogram')
                ->missing('timeToValue')
            );
    }

    public function test_a_deferred_partial_reload_returns_the_adoption_props(): void
    {
        // The asset version must match or Inertia answers 409 (its
        // "your JS bundle is stale, do a full reload" signal), so it is read
        // from the middleware rather than hardcoded.
        $version = (new \App\Http\Middleware\HandleInertiaRequests)->version(request());

        $this->actingAs($this->admin)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) $version,
                'X-Inertia-Partial-Component' => 'Admin/UsageDashboard',
                'X-Inertia-Partial-Data' => 'timeToValue,featureBreadth,breadthHistogram',
            ])
            ->get(route('admin.dashboard'))
            ->assertStatus(200)
            // A partial reload answers with the page object as JSON rather than
            // a rendered view, so assertInertia (which reads view data) does not
            // apply here — assert on the payload directly.
            ->assertJsonCount(3, 'props.timeToValue')
            ->assertJsonStructure([
                'props' => [
                    'timeToValue',
                    'featureBreadth' => ['features', 'denominator', 'unattributed_proposals'],
                    'breadthHistogram',
                ],
            ]);
    }
}
