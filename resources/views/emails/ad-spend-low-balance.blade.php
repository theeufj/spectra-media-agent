@component('mail::message')
# ⚠️ Low Ad Spend Balance

Hi there,

Your ad spend credit balance is running low. Based on your current spending rate, you have approximately **{{ number_format($daysRemaining, 1) }} days** of credit remaining.

**Current Balance:** ${{ number_format($credit->current_balance, 2) }}

**Average Daily Spend:** ${{ number_format($credit->getAverageDailySpend(), 2) }}

## What Happens Next

We'll automatically attempt to replenish your credit balance using your payment method on file. If the automatic charge fails, you may experience campaign interruptions.

@component('mail::button', ['url' => config('app.url') . '/billing'])
Add Credit Now
@endcomponent

## Why Am I Seeing This?

- Your campaigns are spending faster than expected
- The automatic replenishment charge may have failed
- You may have multiple campaigns running

Thanks,<br>
{{ config('app.name') }}
@endcomponent
