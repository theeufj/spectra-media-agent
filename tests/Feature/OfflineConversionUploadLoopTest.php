<?php

namespace Tests\Feature;

use App\Jobs\UploadOfflineConversions;
use App\Models\Customer;
use App\Models\OfflineConversion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The offline conversion uploader must make progress or stop, never neither.
 *
 * A full batch self-redispatches a minute later, and that was gated on the
 * batch being full — nothing more. Every early return (no Google customer id,
 * no client, no conversion action, no pixel, no UET tag) left all 200 rows
 * exactly as it found them: still `pending`, `upload_attempts` still 0. So the
 * next run selected the same 200 rows, re-dispatched again, and MAX_ATTEMPTS
 * could never retire a row no configuration would ever make uploadable — with
 * the hourly RetryOfflineConversions starting a fresh chain alongside it.
 */
class OfflineConversionUploadLoopTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function conversion(Customer $customer, array $overrides = []): OfflineConversion
    {
        return OfflineConversion::create(array_merge([
            'customer_id' => $customer->id,
            'gclid' => 'Cj0KCQiA-demo-click-id',
            'conversion_name' => 'Qualified Lead',
            'conversion_value' => 250.00,
            'currency_code' => 'USD',
            'conversion_time' => now()->subDay(),
            'upload_status' => 'pending',
            'upload_attempts' => 0,
        ], $overrides));
    }

    public function test_an_unconfigured_account_charges_the_attempt_instead_of_leaving_the_row_pending(): void
    {
        // No google_ads_customer_id: uploadToGoogleAds returns before it sends
        // anything. The row used to come back `pending` with 0 attempts, which
        // is the state MAX_ATTEMPTS can never act on.
        $customer = Customer::factory()->create(['google_ads_customer_id' => null]);
        $conversion = $this->conversion($customer);

        (new UploadOfflineConversions($customer->id))->handle();

        $conversion->refresh();

        $this->assertSame('failed', $conversion->upload_status);
        $this->assertEquals(1, $conversion->upload_attempts);
        $this->assertSame('failed', $conversion->upload_results['google_ads']['status']);
    }

    public function test_a_row_at_the_attempt_cap_is_left_alone(): void
    {
        // The other half of the same guarantee: charging attempts is only
        // useful if the cap then retires the row.
        $customer = Customer::factory()->create(['google_ads_customer_id' => null]);
        $conversion = $this->conversion($customer, [
            'upload_status' => 'failed',
            'upload_attempts' => UploadOfflineConversions::MAX_ATTEMPTS,
        ]);

        (new UploadOfflineConversions($customer->id))->handle();

        $this->assertEquals(
            UploadOfflineConversions::MAX_ATTEMPTS,
            $conversion->refresh()->upload_attempts,
            'A retired row must not be picked up again, or the batch never moves past it.'
        );
    }

    public function test_a_conversion_with_no_click_identifier_is_retired_rather_than_left_in_the_batch(): void
    {
        // No gclid, fbclid or msclid: none of the three upload branches ever
        // sees this row, so nothing would have touched it — for ever — while it
        // kept a slot in every 200-row batch ahead of newer conversions.
        $customer = Customer::factory()->create();
        $conversion = $this->conversion($customer, [
            'gclid' => null,
            'fbclid' => null,
            'msclid' => null,
        ]);

        (new UploadOfflineConversions($customer->id))->handle();

        $conversion->refresh();

        $this->assertSame('failed', $conversion->upload_status);
        $this->assertEquals(1, $conversion->upload_attempts);
    }

    public function test_a_full_batch_that_settles_nothing_does_not_requeue_the_job(): void
    {
        Queue::fake();

        // A full batch of rows with nothing left to do: each already succeeded
        // on Google (the per-platform marker in upload_results stops a second
        // upload, which would double-count), and carries no other click id. No
        // branch touches them, so the run makes no progress — and a re-dispatch
        // here is a job that re-queues itself every minute for ever.
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123-456-7890']);

        $rows = [];
        for ($i = 0; $i < 200; $i++) {
            $rows[] = [
                'customer_id' => $customer->id,
                'gclid' => "Cj0KCQiA-demo-{$i}",
                'conversion_name' => 'Qualified Lead',
                'conversion_value' => 250.00,
                'currency_code' => 'USD',
                'conversion_time' => now()->subDay(),
                'upload_status' => 'failed',
                'upload_attempts' => 1,
                'upload_results' => json_encode(['google_ads' => ['status' => 'uploaded']]),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        OfflineConversion::insert($rows);

        (new UploadOfflineConversions($customer->id))->handle();

        Queue::assertNotPushed(UploadOfflineConversions::class);
    }

    public function test_a_full_batch_that_does_settle_rows_still_redispatches_for_the_remainder(): void
    {
        Queue::fake();

        // The gate is progress, not silence: 200 rows charged an attempt is
        // real work, and the rows beyond the batch limit still need a run.
        $customer = Customer::factory()->create(['google_ads_customer_id' => null]);

        $rows = [];
        for ($i = 0; $i < 200; $i++) {
            $rows[] = [
                'customer_id' => $customer->id,
                'gclid' => "Cj0KCQiA-demo-{$i}",
                'conversion_name' => 'Qualified Lead',
                'conversion_value' => 250.00,
                'currency_code' => 'USD',
                'conversion_time' => now()->subDay(),
                'upload_status' => 'pending',
                'upload_attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        OfflineConversion::insert($rows);

        (new UploadOfflineConversions($customer->id))->handle();

        Queue::assertPushed(UploadOfflineConversions::class);
    }
}
