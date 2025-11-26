@component('mail::message')
# ⚠️ Payment Failed - Action Required

Hi there,

We attempted to charge your payment method for your ad spend credit, but the payment failed.

**Error:** {{ $error }}

**Current Balance:** ${{ number_format($credit->current_balance, 2) }}

## What happens next?

You have **24 hours** to update your payment method. If the payment still fails:

1. **After 24 hours:** Your campaign budgets will be reduced by 50%
2. **After 48 hours:** All campaigns will be paused

@component('mail::button', ['url' => config('app.url') . '/billing'])
Update Payment Method
@endcomponent

## Tips to fix this:

- Ensure your card hasn't expired
- Check that you have sufficient funds
- Contact your bank if the issue persists

If you have any questions, please reply to this email.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
