<?php

namespace Tests\Feature;

use App\Models\BrandGuideline;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A page that links by uuid must be given the uuid.
 *
 * `Customer` routes by uuid (`HasPublicUuid`), so every link and every form
 * post on these pages is built as `route(..., customer.uuid)`. Three
 * controllers projected the customer through `only([...])` and left uuid out
 * of the list. The field is simply absent from the prop, Ziggy raises
 * "'customer' parameter is required", and the ErrorBoundary takes the whole
 * page down — not the one link, the page.
 *
 * It reached production on the brand guidelines screen, where the offending
 * link sits inside the `extraction_warning` panel: invisible for every
 * customer whose site read cleanly, and a blank screen for the one whose did
 * not. The failing case was a customer whose extraction had otherwise
 * succeeded — 39 pages crawled, guideline scored 92 — so nothing upstream
 * looked wrong and the logs recorded a clean run.
 *
 * `npm run lint` cannot catch this: `customer` is defined, and only the
 * property is missing. It is a server-side omission with a client-side
 * crash, which is why it is asserted here, against the prop the controller
 * actually sends.
 */
class CustomerPropCarriesUuidTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $role = Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin']));
        $admin = User::factory()->create();
        $admin->roles()->attach($role);

        return $admin;
    }

    /** @return array{User, Customer} */
    private function customer(): array
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        return [$user, $customer];
    }

    public function test_the_brand_guidelines_page_can_build_its_change_address_link(): void
    {
        [$user, $customer] = $this->customer();

        // The warning is what renders the link, so it is what triggers the crash.
        BrandGuideline::create([
            'customer_id' => $customer->id,
            'brand_voice' => 'Direct and warm',
            'tone_attributes' => ['friendly'],
            'color_palette' => ['primary' => '#ff0000'],
            'typography' => ['heading' => 'Inter'],
            'visual_style' => ['overall_aesthetic' => 'clean', 'imagery_style' => 'photographic'],
            'messaging_themes' => ['speed'],
            'unique_selling_propositions' => ['fast'],
            'target_audience' => ['primary' => 'founders'],
            'brand_personality' => ['helpful'],
            'extracted_at' => now(),
            'extraction_warning' => 'The page looks like a placeholder — the site may not be live yet.',
        ]);

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->get(route('brand-guidelines.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('customer.uuid', $customer->uuid)
            );
    }

    public function test_the_facebook_ad_account_page_can_build_its_form_posts(): void
    {
        // Admin-only, so this one broke the operator's screen rather than a
        // customer's — the same defect, found later.
        [, $customer] = $this->customer();

        $this->actingAs($this->admin())
            ->get(route('customers.facebook.setup', $customer->uuid))
            ->assertInertia(fn (Assert $page) => $page
                ->where('customer.uuid', $customer->uuid)
            );
    }

    public function test_the_admin_workspace_can_build_its_navigation(): void
    {
        [, $customer] = $this->customer();

        $this->actingAs($this->admin())
            ->get(route('admin.customers.workspace', $customer->uuid))
            ->assertInertia(fn (Assert $page) => $page
                ->where('customer.uuid', $customer->uuid)
            );
    }
}
