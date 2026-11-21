<?php

namespace Tests\Feature;

use App\Models\AdSpendCredit;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Conversions\MerchantOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ChannelFundingConcurrencyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_two_workers_contending_for_one_wallet_cannot_reserve_the_same_credit(): void
    {
        $this->workspace(function (Customer $customer, Campaign $campaign) {
            AdSpendCredit::factory()->for($customer)->create(['currency' => 'AUD', 'current_balance' => 100]);
            $ids = [];
            foreach (['Google Ads', 'Facebook Ads'] as $platform) {
                $ids[] = Strategy::factory()->for($campaign)->create(['platform' => $platform, 'daily_budget' => 10, 'signed_off_at' => now(), 'deployment_status' => 'awaiting_launch',
                    'launch_intent' => ['version' => 2, 'id' => (string) Str::uuid(), 'daily_cents' => 1000, 'max_reserve_cents' => 7000]])->id;
            }
            DB::beginTransaction();
            DB::table('customers')->where('id', $customer->id)->lockForUpdate()->firstOrFail();
            $this->contend($ids, <<<'WORKER'
                $strategy = App\Models\Strategy::findOrFail($argv[1]);
                $google = $strategy->platform === 'Google Ads';
                $customer = $strategy->campaign->customer;
                $proof = ['ready' => true, 'account' => $google ? $customer->cleanGoogleCustomerId() : substr($customer->facebook_ads_account_id, 4), 'currency' => 'AUD', 'payer' => 'agency'];
                $result = app(App\Services\Billing\LaunchFunding::class)->reserve($strategy, $proof);
                echo $result['ready'] ? 'ACCEPTED' : 'BLOCKED';
                WORKER);
            $this->assertSame(7000, (int) DB::table('launch_funding_reservations')->where('customer_id', $customer->id)->sum('held_cents'));
            $this->assertSame('100.00', $customer->adSpendCredit()->value('current_balance'));
        });
    }

    public function test_simultaneous_refunds_for_one_order_cannot_exceed_the_paid_amount(): void
    {
        $this->workspace(function (Customer $customer, Campaign $campaign) {
            $file = app(\App\Services\Conversions\AdvertiserOutcomeOrigin::class)->file($customer, 'https://qa.example');
            $customer->forceFill(['verified_outcome_origin' => hash('sha256', $file['contents']), 'outcome_origin_verified_at' => now()])->save();
            app(MerchantOrders::class)->purchase($customer->id, 'shared-order', 'AUD', '100.00', now()->subDay());
            $ids = [];
            foreach (['Google Ads', 'Facebook Ads'] as $platform) {
                $strategy = Strategy::factory()->for($campaign)->create(['platform' => $platform, 'conversion_goals' => ['primary_goal' => 'Purchase']]);
                $strategy->forceFill(['conversion_webhook_secret' => Str::random(64)])->save();
                $ids[] = $strategy->id;
            }
            DB::beginTransaction();
            DB::table('merchant_order_receipts')->where('customer_id', $customer->id)->lockForUpdate()->firstOrFail();
            $this->contend($ids, <<<'WORKER'
                $strategy = App\Models\Strategy::findOrFail($argv[1]);
                $timestamp = (string) time();
                $body = json_encode(['goal' => 'refund', 'source_id' => 'refund-'.$strategy->id, 'order_id' => 'shared-order',
                    'mode' => 'live', 'occurred_at' => now()->toIso8601String(), 'amount' => '70.00', 'currency' => 'AUD', 'source_url' => 'https://qa.example/refunds'], JSON_THROW_ON_ERROR);
                $request = Illuminate\Http\Request::create(route('outcomes.record', $strategy->uuid), 'POST', [], [], [],
                    ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_OUTCOME_TIMESTAMP' => $timestamp,
                    'HTTP_X_OUTCOME_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, $strategy->conversion_webhook_secret)], $body);
                $status = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request)->getStatusCode();
                echo match ($status) {202 => 'ACCEPTED', 422 => 'BLOCKED', default => 'ERROR-'.$status};
                WORKER);
            $this->assertSame(1, DB::table('merchant_refund_receipts')->where('customer_id', $customer->id)->count());
            $this->assertSame(7000, (int) DB::table('merchant_order_receipts')->where('customer_id', $customer->id)->value('refunded_cents'));
        });
    }

    public function test_two_php_workers_open_one_hosted_subscription_checkout(): void
    {
        $this->workspace(function (Customer $customer) {
            $user = \App\Models\User::factory()->create(['stripe_id' => 'cus_concurrent_'.\Illuminate\Support\Str::random(10)]);
            $customer->users()->attach($user->id, ['role' => 'owner']);
            $file = tempnam(sys_get_temp_dir(), 'managed_checkout_race_');
            try {
                $fixture = var_export($file, true);
                DB::beginTransaction();
                DB::table('users')->where('id', $user->id)->lockForUpdate()->firstOrFail();
                $this->contend([$customer->id, $customer->id], '$fixture = '.$fixture.";\n".<<<'WORKER'
                    $customer = App\Models\Customer::findOrFail($argv[1]);
                    $user = $customer->users()->firstOrFail();
                    config(['cashier.secret' => 'sk_test_concurrent', 'services.stripe.secret' => 'sk_test_concurrent']);
                    Stripe\ApiRequestor::setHttpClient(new Tests\Fakes\ManagedCheckoutStripeTransport($fixture));
                    $result = app(App\Services\Billing\ManagedSubscriptionCheckout::class)->start($user, $customer, App\Models\Plan::where('slug', 'starter')->firstOrFail());
                    echo $result['state'] === 'open' ? 'ACCEPTED' : 'BLOCKED';
                    WORKER, ['ACCEPTED', 'ACCEPTED']);
                $state = (new \Tests\Fakes\ManagedCheckoutStripeTransport($file))->state();
                $this->assertCount(1, $state['creates']);
                $this->assertCount(1, $state['sessions']);
                $this->assertSame(1, DB::table('managed_subscription_checkouts')->where('customer_id', $customer->id)->count());
            } finally {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                unlink($file);
                $customer->users()->detach($user->id);
                $user->delete();
            }
        });
    }

    private function workspace(\Closure $run): void
    {
        $original = DB::getDefaultConnection();
        $configuration = config("database.connections.{$original}");
        if ($configuration['driver'] !== 'pgsql') {
            $this->markTestSkipped('This test exercises real PostgreSQL row locks.');
        }
        // Committed fixtures on a separate connection are visible to both worker processes.
        config(['database.connections.funding_race' => $configuration]);
        DB::setDefaultConnection('funding_race');
        $customer = null;
        $campaign = null;
        try {
            $customer = Customer::factory()->create(['website' => 'https://qa.example', 'currency_code' => 'AUD', 'google_ads_customer_id' => fake()->unique()->numerify('1#########'), 'facebook_ads_account_id' => 'act_'.fake()->unique()->numerify('2#########')]);
            $campaign = Campaign::factory()->for($customer)->create(['daily_budget' => 20, 'approved_daily_budget' => 20, 'budget_confirmed_at' => now()]);
            $run($customer, $campaign);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($customer) {
                DB::table('launch_funding_reservations')->where('customer_id', $customer->id)->delete();
                DB::table('ad_spend_credits')->where('customer_id', $customer->id)->delete();
                if ($campaign) {
                    DB::table('strategies')->where('campaign_id', $campaign->id)->delete();
                    DB::table('campaigns')->where('id', $campaign->id)->delete();
                }
                DB::table('customers')->where('id', $customer->id)->delete();
            }
            DB::setDefaultConnection($original);
            DB::purge('funding_race');
        }
    }

    private function contend(array $ids, string $action, array $expected = ['ACCEPTED', 'BLOCKED']): void
    {
        $configuration = config('database.connections.funding_race');
        $application = 'agency_race_'.Str::random(16);
        $bootstrap = <<<'BOOT'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            Illuminate\Support\Facades\DB::selectOne("select set_config('application_name', ?, false)", [$argv[2]]);
            BOOT;
        $workers = [];
        try {
            foreach ($ids as $id) {
                $worker = new Process([PHP_BINARY, '-r', $bootstrap."\n".$action, (string) $id, $application], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $configuration['host'], 'DB_PORT' => (string) $configuration['port'],
                    'DB_DATABASE' => $configuration['database'], 'DB_USERNAME' => $configuration['username'], 'DB_PASSWORD' => $configuration['password'] ?? '',
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                ]);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 15;
            do {
                DB::select('select pg_stat_clear_snapshot()');
                $waiting = (int) DB::selectOne("select count(*) as waiting from pg_stat_activity where application_name = ? and wait_event_type = 'Lock'", [$application])->waiting;
                if ($waiting === 2) {
                    break;
                }
                usleep(25_000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $waiting, 'Both independent PostgreSQL connections must be competing for the same row.');
            DB::commit();
            $outcomes = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $output = trim($worker->getOutput());
                $outcomes[] = str_ends_with($output, 'ACCEPTED') ? 'ACCEPTED' : (str_ends_with($output, 'BLOCKED') ? 'BLOCKED' : $output);
            }
            sort($outcomes);
            $this->assertSame($expected, $outcomes);
        } finally {
            foreach ($workers as $worker) {
                $worker->stop(1);
            }
        }
    }
}
