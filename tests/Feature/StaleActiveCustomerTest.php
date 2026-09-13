<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * A session pointing at a customer you cannot reach is not a missing page.
 *
 * It happens whenever the account is deleted, someone is removed from it, or a
 * session simply outlives the row. Fifteen call sites treated it as fatal —
 * `customers()->findOrFail(session('active_customer_id'))` — so the brand
 * guidelines page, the campaign wizard, strategies, creative briefs and
 * deployment all returned 404 on a session that had simply gone stale.
 *
 * Found by deleting a test account out from under a live session: clicking
 * through from the verification email landed on /brand-guidelines?review=1 and
 * a dead end.
 *
 * The dashboard is the exception and always was — it carries its own recovery,
 * re-pointing the session at the user's first customer before it reads
 * anything. That is precisely the behaviour the other fourteen were missing,
 * and it is now in getActiveCustomer() where all of them can reach it.
 */
class StaleActiveCustomerTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{User, Customer} */
    private function userWithCustomer(): array
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'user'])));

        $customer = Customer::factory()->create(['is_sandbox' => false]);
        $customer->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $customer];
    }

    /** @return array<string, array{string}> */
    public static function signedInPages(): array
    {
        return [
            'the dashboard' => ['/dashboard'],
            'brand guidelines' => ['/brand-guidelines'],
            'campaigns' => ['/campaigns'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('signedInPages')]
    public function test_a_deleted_active_customer_does_not_404(string $path): void
    {
        [$user, $customer] = $this->userWithCustomer();
        $survivor = Customer::factory()->create(['is_sandbox' => false]);
        $survivor->users()->attach($user->id, ['role' => 'owner']);

        // The session still names the customer that has just gone.
        $customer->users()->detach();
        $customer->forceDelete();

        $response = $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->get($path);

        $this->assertNotSame(404, $response->getStatusCode(), "{$path} is a dead end on a stale session");
    }

    public function test_the_helper_forgets_a_stale_id_and_falls_back(): void
    {
        /*
         * Asserted directly on getActiveCustomer rather than through a page.
         *
         * HandleInertiaRequests fills active_customer_id whenever the key is
         * *absent*, so an HTTP-level assertion passes whether or not this
         * helper does anything — which is how the first version of this test
         * passed with the fix removed. Forgetting the stale key is the
         * load-bearing part: leave it present and the middleware never gets its
         * turn.
         */
        [$user, $customer] = $this->userWithCustomer();
        $survivor = Customer::factory()->create(['is_sandbox' => false]);
        $survivor->users()->attach($user->id, ['role' => 'owner']);

        $customer->users()->detach();
        $customer->forceDelete();

        $request = Request::create('/');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);
        session(['active_customer_id' => $customer->id]);

        $resolver = new class extends \App\Http\Controllers\Controller
        {
            public function resolve(Request $request): ?Customer
            {
                return $this->getActiveCustomer($request);
            }
        };

        $this->assertSame($survivor->id, $resolver->resolve($request)?->id);
        $this->assertSame($survivor->id, session('active_customer_id'));
    }

    public function test_a_customer_belonging_to_someone_else_is_never_adopted(): void
    {
        [$user] = $this->userWithCustomer();
        $stranger = Customer::factory()->create(['is_sandbox' => false]);
        $stranger->users()->attach(User::factory()->create()->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $stranger->id])
            ->get('/dashboard');

        // Recovering from a stale id must not turn into reading someone else's
        // account because their id happened to be in the session.
        $this->assertNotSame($stranger->id, session('active_customer_id'));
    }

    public function test_a_user_with_no_customers_is_sent_to_start_not_to_a_404(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'user'])));

        $response = $this->actingAs($user)
            ->withSession(['active_customer_id' => 999999])
            ->get('/brand-guidelines');

        $this->assertNotSame(404, $response->getStatusCode());
        $response->assertRedirect(route('quick-start', absolute: false));
    }
}
