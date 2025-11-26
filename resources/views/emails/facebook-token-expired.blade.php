<x-mail::message>
# Your Facebook Connection Has Expired

Hi {{ $customerName }},

Your Facebook/Instagram Ads connection has **expired**. Your ad campaigns have been affected and need your immediate attention.

<x-mail::button :url="$reconnectUrl">
Reconnect Facebook Now
</x-mail::button>

## What's affected?

- Your Facebook and Instagram ad campaigns may have stopped
- New campaign deployments are blocked
- Performance data is no longer syncing

## How to fix this

1. Click the button above to go to your profile
2. Click "Connect Facebook" to re-authorize
3. Your campaigns will automatically resume

This process takes less than a minute.

If you have any questions, please don't hesitate to reach out to our support team.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
