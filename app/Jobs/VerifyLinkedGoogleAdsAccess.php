<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Customer;
use App\Services\EarlyExitFeeService;
use App\Services\GoogleAds\VerifyAccountAccess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The drift detector for bring-your-own-account customers.
 *
 * A customer who links their own Google Ads account can also unlink it —
 * typically right after we've configured everything. Without this job the
 * first symptom was nightly agents erroring against an account we could no
 * longer touch. Now the loss is detected the day it happens: the link is
 * marked revoked (which quietly stands the agents down), the customer gets
 * a what-you-just-lost email, the admin is alerted, and the early-exit
 * terms are assessed if they're inside the minimum period.
 */
class VerifyLinkedGoogleAdsAccess implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $linked = Customer::where('google_ads_link_status', 'active')
            ->whereNotNull('google_ads_customer_id')
            ->get();

        foreach ($linked as $customer) {
            try {
                $accessible = $this->probe($customer);

                // null = inconclusive (quota, transport). Never treat as
                // revocation — a false churn alarm costs trust both ways.
                if ($accessible !== false) {
                    continue;
                }

                $customer->forceFill(['google_ads_link_status' => 'revoked'])->save();

                Log::warning('VerifyLinkedGoogleAdsAccess: manager access revoked', [
                    'customer_id' => $customer->id,
                    'google_ads_customer_id' => $customer->google_ads_customer_id,
                ]);

                AgentActivity::record(
                    'monitoring',
                    'google_ads_link_revoked',
                    "Manager access to \"{$customer->name}\"'s Google Ads account ({$customer->google_ads_customer_id}) has been revoked — management has stopped.",
                    $customer->id
                );

                foreach ($customer->users as $user) {
                    Mail::to($user->email)->send(new \App\Mail\LinkAccessLost($customer));
                }

                Mail::raw(
                    "Google Ads manager access REVOKED\n\nCustomer: {$customer->name} (#{$customer->id})\nAccount: {$customer->google_ads_customer_id}\n\nAgents are standing down for this account. Early-exit assessment (if applicable) follows separately.",
                    fn ($m) => $m->to(config('app.admin_email'))->subject("Link revoked: {$customer->name}")
                );

                app(EarlyExitFeeService::class)->assess($customer, 'link_revoked');
            } catch (\Throwable $e) {
                report($e);
                Log::error('VerifyLinkedGoogleAdsAccess: check failed', [
                    'customer_id' => $customer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Separated so tests can stub the Google round-trip.
     */
    protected function probe(Customer $customer): ?bool
    {
        return (new VerifyAccountAccess($customer))();
    }
}
