<?php

namespace App\Services\Creative;

use App\Models\CreativeUsage;
use App\Models\Customer;
use App\Services\CreativeQuotaService;
use Illuminate\Support\Facades\DB;

/** Reserve one concept across the customer's campaigns before a worker calls a provider. */
class StarterCreativeQuota
{
    public static function applies(?Customer $customer): bool
    {
        return $customer?->resolvePlan()->slug === 'starter';
    }

    public function remaining(?Customer $customer, string $type): ?int
    {
        if (! $customer || ! self::applies($customer)) {
            return null;
        }
        $bucket = $this->column($type);
        $usage = CreativeUsage::where('customer_id', $customer->id)->where('period', app(CreativeQuotaService::class)->periodFor($customer))->first();
        $limit = (int) ($customer->resolvePlan()->creative_limits[$bucket] ?? 0);

        return max(0, $limit + ($usage->{'bonus_'.$bucket} ?? 0) - ($usage->{$bucket.'_used'} ?? 0));
    }

    public static function key(Customer $customer, string $type, string $slot): string
    {
        return hash('sha256', $customer->id.'|'.$type.'|'.$slot);
    }

    /** Null means an unmetered plan. False means exhausted or already delivered. */
    public function reserve(Customer $customer, string $type, string $slot): string|false|null
    {
        if (! self::applies($customer)) {
            return null;
        }
        $key = self::key($customer, $type, $slot);

        return DB::transaction(function () use ($customer, $type, $key) {
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $prior = DB::table('creative_usage_reservations')->where('work_key', $key)->lockForUpdate()->first();
            if ($prior) {
                return $prior->completed ? false : $key;
            }
            $owner = $customer->users()->orderByRaw("CASE WHEN customer_user.role = 'owner' THEN 0 ELSE 1 END")->orderBy('users.id')->first();
            if (! $owner) {
                return false;
            }
            $quotas = app(CreativeQuotaService::class);
            $usage = $quotas->getOrCreateUsage($owner, $customer);
            $usage = CreativeUsage::whereKey($usage->id)->lockForUpdate()->firstOrFail();
            $column = $this->column($type);
            $limit = (int) ($customer->resolvePlan()->creative_limits[$column] ?? 0);
            if ($limit < 1 || $usage->{$column.'_used'} >= $limit + $usage->{'bonus_'.$column}) {
                return false;
            }
            $usage->increment($column.'_used');
            DB::table('creative_usage_reservations')->insert(['creative_usage_id' => $usage->id, 'work_key' => $key, 'type' => $type, 'created_at' => now()]);

            return $key;
        });
    }

    /** Called inside the transaction that persists the generated image rows. */
    public function canFinish(?string $key): bool
    {
        return $key === null || DB::table('creative_usage_reservations')->where('work_key', $key)
            ->where('completed', false)->lockForUpdate()->first() !== null;
    }

    public function finish(?string $key): void
    {
        if ($key !== null) {
            DB::table('creative_usage_reservations')->where('work_key', $key)->update(['completed' => true]);
        }
    }

    /** Return failed or superseded work to its original billing period (including retries after rollover). */
    public function release(?string $key): void
    {
        if ($key === null) {
            return;
        }
        DB::transaction(function () use ($key) {
            $reservation = DB::table('creative_usage_reservations')->where('work_key', $key)->where('completed', false)->lockForUpdate()->first();
            if ($reservation) {
                CreativeUsage::whereKey($reservation->creative_usage_id)->decrement($this->column($reservation->type).'_used');
                DB::table('creative_usage_reservations')->where('id', $reservation->id)->delete();
            }
        });
    }

    private function column(string $type): string
    {
        return match ($type) {
            'image' => 'image_generations', 'video' => 'video_generations', 'refinement' => 'refinements',
            default => throw new \InvalidArgumentException('Unknown creative quota.'),
        };
    }
}
