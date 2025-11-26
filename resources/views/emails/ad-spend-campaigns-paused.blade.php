@component('mail::message')
# ⛔ All Campaigns Have Been Paused

Hi there,

Due to continued payment failures, we have **paused all of your advertising campaigns**.

**Campaigns Paused At:** {{ $credit->campaigns_paused_at->format('M j, Y g:i A') }}

## What This Means

- Your ads are no longer running on Google or Facebook
- You are not being charged for ad spend
- Your campaign performance data is preserved

## How To Resume

Simply update your payment method and your campaigns will be automatically resumed within 1 hour.

@component('mail::button', ['url' => config('app.url') . '/billing', 'color' => 'green'])
Add Payment & Resume Campaigns
@endcomponent

## Need Help?

If you're experiencing financial difficulties or have questions about your account, please reply to this email. We're happy to work with you.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
