<?php

namespace App\Observers;

use App\Models\Customer;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\CriticalAgentAlert;
use Illuminate\Support\Facades\Log;

class UserObserver
{
    /**
     * Decide what happens to a user's customers before the user row goes.
     *
     * `customer_user.user_id` is ON DELETE CASCADE, so deleting a user takes
     * their pivot rows with it — but nothing cascades on to the customer. The
     * customer row survives with no owner: unreachable by every user, invisible
     * in every UI, and still attached to whatever campaigns and ad-spend credit
     * it had. Six of twenty-two customers in production got there this way, and
     * the same deletes stranded queued jobs holding a serialized User, which is
     * where the "No query results for model [App\Models\User]" failures in
     * CrawlPage came from.
     *
     * A customer left with no other owner is either worthless (nothing
     * deployed, no money) — in which case deleting it is the cleanup nobody was
     * doing — or it is not, in which case a human has to decide, because it may
     * be spending money on live ads right now. This never deletes the second
     * kind; it alerts instead. Customer uses SoftDeletes, so even the first kind
     * is retired rather than destroyed.
     */
    public function deleting(User $user): void
    {
        // Read the pivot before the cascade empties it.
        $customers = $user->customers()->get();

        foreach ($customers as $customer) {
            $otherOwners = $customer->users()->where('users.id', '!=', $user->getKey())->count();

            if ($otherOwners > 0) {
                continue;
            }

            if ($this->isDisposable($customer)) {
                // Soft-delete — Customer uses SoftDeletes, so this takes the row
                // out of every query without destroying it.
                Log::info('Soft-deleting customer left with no owner', [
                    'customer_id' => $customer->id,
                    'deleted_user_id' => $user->getKey(),
                ]);

                $customer->delete();

                continue;
            }

            $this->alertOrphaned($customer, $user);
        }
    }

    /**
     * Nothing deployed and no money: safe to remove with the last owner.
     */
    private function isDisposable(Customer $customer): bool
    {
        $hasDeployedCampaign = $customer->campaigns()
            ->where(function ($q) {
                $q->whereNotNull('google_ads_campaign_id')
                    ->orWhereNotNull('facebook_ads_campaign_id')
                    ->orWhereNotNull('microsoft_ads_campaign_id')
                    ->orWhereNotNull('linkedin_campaign_id');
            })
            ->exists();

        if ($hasDeployedCampaign) {
            return false;
        }

        // hasOne, not hasMany — one credit row per customer.
        $balance = (float) ($customer->adSpendCredit()->value('current_balance') ?? 0);

        return $balance <= 0.0;
    }

    /**
     * Live ads or unspent credit — leave the row alone and get a human to it.
     */
    private function alertOrphaned(Customer $customer, User $user): void
    {
        Log::warning('Customer left with no owner and cannot be auto-removed', [
            'customer_id' => $customer->id,
            'deleted_user_id' => $user->getKey(),
        ]);

        CriticalAgentAlert::deliver(
            'customer_orphaned',
            "Customer #{$customer->id} ({$customer->name}) has no owner",
            'Its last user was deleted, but it has deployed campaigns or unspent ad-spend credit, '
                .'so it was not removed. It is now unreachable in the UI while its campaigns keep running. '
                .'Reassign it to a user or wind it down.',
            [
                'customer_id' => $customer->id,
                'deleted_user_id' => $user->getKey(),
                'deleted_user_email' => $user->email,
            ],
            NotificationTemplate::RECIPIENTS_ADMINS,
            $customer,
        );
    }
}
