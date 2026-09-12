<?php

use App\Models\Customer;
use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A plan belongs to the account, not to a person on it.
 *
 * Entitlements — which platforms may deploy, image quotas, CRO audits,
 * white-label — are consumed per customer, by jobs that have no acting user.
 * Every one of them therefore had to guess which user spoke for the account,
 * and the codebase held four different guesses:
 *
 *   DeployCampaign      users()->wherePivot('role','owner')->first() ?? users()->first()
 *   GenerateStrategy    users()->first()        // its own comment called this a legacy fallback
 *   OptimizeCampaigns   users()?->first()
 *   GenerateImage       users()->first()
 *
 * On an account with several people and no ordering, three of those four did
 * not even prefer an owner — they took whichever row the database returned. Two
 * teammates on different plans meant the platforms a campaign deployed to were
 * decided by row order.
 *
 * `customers.plan_id` makes the account's entitlement a fact rather than a
 * derivation. Users still belong to customers through the pivot; nothing about
 * who logs in changes what the account may do.
 *
 * Backfill takes the highest plan held by anyone on the customer. That is a
 * one-off reconstruction of what each account has in practice been entitled to,
 * not an ongoing rule — after this, the column is the only answer. Customers
 * with nobody attached, or whose people are all on free, land on free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('service_type')
                ->constrained('plans')->nullOnDelete();

            // Which single platform a Starter account runs on. Account-level for
            // the same reason the plan is: it answers "what may this business
            // deploy to", not "what did this person choose".
            $table->string('starter_platform')->nullable()->after('plan_id');
        });

        $free = Plan::where('slug', 'free')->first();

        Customer::withoutEvents(function () use ($free) {
            // withTrashed(), because Customer soft-deletes and the global scope
            // would quietly skip them — six rows in production, all of them
            // restorable. A customer that came back would have come back with
            // no plan.
            Customer::withTrashed()->chunkById(100, function ($customers) use ($free) {
                foreach ($customers as $customer) {
                    // Highest sort_order among the account's people. price_cents
                    // cannot order this — Agency is 0 because it is "contact us",
                    // which would rank it below Free.
                    $best = DB::table('customer_user')
                        ->join('users', 'users.id', '=', 'customer_user.user_id')
                        ->join('plans', 'plans.id', '=', 'users.assigned_plan_id')
                        ->where('customer_user.customer_id', $customer->id)
                        ->orderByDesc('plans.sort_order')
                        ->select('plans.id as plan_id', 'users.starter_platform')
                        ->first();

                    $customer->forceFill([
                        'plan_id' => $best->plan_id ?? $free?->id,
                        'starter_platform' => $best->starter_platform ?? null,
                    ])->saveQuietly();
                }
            });
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
            $table->dropColumn('starter_platform');
        });
    }
};
