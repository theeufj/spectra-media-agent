<?php

namespace App\Services\Conversions;

use App\Models\Customer;
use App\Services\Billing\LaunchFunding;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MerchantOrders
{
    public function purchase(int $customerId, string $orderId, string $currency, string $amount, CarbonInterface $occurred): void
    {
        $key = hash('sha256', $orderId);
        $cents = LaunchFunding::cents($amount);
        $orders = DB::table('merchant_order_receipts');
        $orders->insertOrIgnore(['customer_id' => $customerId, 'order_key' => $key, 'currency' => $currency,
            'gross_cents' => $cents, 'occurred_at' => $occurred->toIso8601String()]);
        $prior = $orders->where('customer_id', $customerId)->where('order_key', $key)->firstOrFail();
        abort_unless($prior->currency === $currency && (int) $prior->gross_cents === $cents, 409, 'This order already has a different amount or currency.');
    }

    public function refund(int $customerId, array $input, CarbonInterface $occurred): int
    {
        $cents = LaunchFunding::cents($input['amount']);
        $key = hash('sha256', $input['source_id']);

        return DB::transaction(function () use ($customerId, $input, $occurred, $cents, $key) {
            $order = DB::table('merchant_order_receipts')->where('customer_id', $customerId)->where('order_key', hash('sha256', $input['order_id']))
                ->lockForUpdate()->first();
            abort_unless($order && $order->currency === $input['currency'], 422, 'A matching paid order in this currency is required.');
            abort_if($occurred->lt(Carbon::parse($order->occurred_at)), 422, 'The refund precedes the paid order.');
            // The unique receipt and order lock make retries and simultaneous partial refunds safe, even across channels.
            $inserted = DB::table('merchant_refund_receipts')->insertOrIgnore(['customer_id' => $customerId, 'order_id' => $order->id,
                'refund_key' => $key, 'amount_cents' => $cents, 'occurred_at' => $occurred->toIso8601String()]);
            $refund = DB::table('merchant_refund_receipts')->where('customer_id', $customerId)->where('refund_key', $key)->firstOrFail();
            abort_unless((int) $refund->order_id === (int) $order->id && (int) $refund->amount_cents === $cents
                && Carbon::parse($refund->occurred_at)->equalTo($occurred), 409, 'This refund ID was already used for different details.');
            if ($inserted) {
                abort_if($cents > (int) $order->gross_cents - (int) $order->refunded_cents, 422, 'The refund exceeds the unrefunded order amount.');
                DB::table('merchant_order_receipts')->where('id', $order->id)->increment('refunded_cents', $cents);
            }

            return $refund->id;
        });
    }

    /** Group by the merchant's order/refund date, preserving negative net days and distinct currencies. */
    public function summary(Customer $customer, CarbonInterface $from, CarbonInterface $through): array
    {
        $orders = DB::table('merchant_order_receipts')->where('customer_id', $customer->id)
            ->where('occurred_at', '>=', $from->toIso8601String())->where('occurred_at', '<', $through->toIso8601String())
            ->groupBy('currency')->selectRaw('currency, COUNT(*) as orders, SUM(gross_cents) as gross_cents')->get()->keyBy('currency');
        $refunds = DB::table('merchant_refund_receipts as r')->join('merchant_order_receipts as o', 'o.id', '=', 'r.order_id')
            ->where('r.customer_id', $customer->id)->where('r.occurred_at', '>=', $from->toIso8601String())->where('r.occurred_at', '<', $through->toIso8601String())
            ->groupBy('o.currency')->selectRaw('o.currency, COUNT(*) as refunds, SUM(r.amount_cents) as refund_cents')->get()->keyBy('currency');
        $totals = [];
        foreach ($orders->keys()->merge($refunds->keys())->unique()->sort() as $currency) {
            $gross = (int) ($orders->get($currency)->gross_cents ?? 0);
            $returned = (int) ($refunds->get($currency)->refund_cents ?? 0);
            $totals[$currency] = ['orders' => (int) ($orders->get($currency)->orders ?? 0), 'refunds' => (int) ($refunds->get($currency)->refunds ?? 0),
                'gross_cents' => $gross, 'refund_cents' => $returned, 'net_cents' => $gross - $returned];
        }

        return $totals;
    }
}
