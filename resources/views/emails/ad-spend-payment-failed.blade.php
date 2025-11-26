@component('mail::message')
# 🚨 Payment Still Failing - Budgets Reduced

Hi there,

Your payment has failed for the second time. To protect your account, we've **reduced your campaign budgets by 50%**.

**Error:** {{ $error }}

**Current Balance:** ${{ number_format($credit->current_balance, 2) }}

## Urgent Action Required

If we cannot successfully charge your payment method within the next **24 hours**, all your campaigns will be **paused**.

@component('mail::button', ['url' => config('app.url') . '/billing', 'color' => 'red'])
Fix Payment Now
@endcomponent

## Current Status:

| Status | Value |
|--------|-------|
| Budget Reduction | 50% |
| Failed Attempts | {{ $credit->failed_charge_count }} |
| Time Until Pause | ~24 hours |

Please update your payment method immediately to prevent campaign interruption.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
