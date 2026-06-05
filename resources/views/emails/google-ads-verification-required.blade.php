<x-mail::message>
# Action Required: Verify Your Google Ads Account

Hi {{ $user->name }},

Your Google Ads account has been set up under our platform, but **Google requires identity verification before your campaigns can go live**.

This is a standard Google requirement for all new advertising accounts. Until verification is complete, your campaigns cannot be deployed.

<x-mail::panel>
**Account:** {{ $customer->name }}@if($customer->google_ads_customer_id) (ID: {{ $customer->google_ads_customer_id }})@endif
@if($campaign)
**Campaign:** {{ $campaign->name }}
@endif
</x-mail::panel>

## How to Complete Verification

<x-mail::button url="https://ads.google.com/aw/businessidentity">
Complete Google Ads Verification
</x-mail::button>

**Steps:**
1. Click the button above to open Google's verification portal
2. Sign in with the Google account linked to your ads account
3. Submit your business identity documents as requested
4. Google typically reviews submissions within 3–5 business days

## What Happens Next

Once Google approves your verification, your campaigns will deploy automatically on your next publish attempt. You don't need to do anything else on our end.

If you have any questions or need help, just reply to this email.

Thanks,<br>
The {{ config('app.name') }} Team
</x-mail::message>
