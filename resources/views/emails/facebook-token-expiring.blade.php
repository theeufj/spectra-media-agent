<x-mail::message>
# Your Facebook Connection Will Expire Soon

Hi {{ $customerName }},

Your Facebook/Instagram Ads connection will expire in **{{ $daysRemaining }} {{ $daysRemaining === 1 ? 'day' : 'days' }}**.

To ensure your advertising campaigns continue running without interruption, please reconnect your Facebook account.

<x-mail::button :url="$reconnectUrl">
Reconnect Facebook
</x-mail::button>

## What happens if I don't reconnect?

- Your Facebook and Instagram ad campaigns will stop running
- You won't be able to create new campaigns
- Performance data syncing will stop

This only takes a minute and keeps your campaigns running smoothly.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
