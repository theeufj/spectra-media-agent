@component('mail::message')
# ✅ Payment Received - Campaigns Resumed!

Hi there,

Great news! Your payment has been successfully processed and **all your campaigns are now running again**.

**New Credit Balance:** ${{ number_format($credit->current_balance, 2) }}

## What Happened

- Payment was successfully processed
- Campaign budgets restored to 100%
- All paused campaigns have been resumed

## Your Campaigns

Your ads should begin serving within the next 30-60 minutes as the ad networks re-enable them.

@component('mail::button', ['url' => config('app.url') . '/campaigns'])
View Your Campaigns
@endcomponent

Thank you for resolving this promptly. If you have any questions, please don't hesitate to reach out.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
