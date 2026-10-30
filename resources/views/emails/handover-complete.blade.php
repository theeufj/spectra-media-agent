@extends('layouts.email')

@section('title', 'Your Google account access is confirmed')

@section('content')
    <h1>Your account access is confirmed</h1>
    <p>Your campaign for {{ $customer->name }} has been created and verified. Google has confirmed administrator access for {{ implode(' and ', $invited) }} to Ads account {{ $customer->google_ads_customer_id }}. We have also verified that you control the selected Tag Manager account.</p>
    <p>This confirms account access, not that conversion tracking has passed a website test. Handover does not switch on your ads or change your budget.</p>
    <ol style="color: #4a5568; padding-left: 20px;">
        <li><strong>Add your billing</strong> in Google Ads. Advertising spend is paid separately to Google.</li>
        <li><strong>Check your conversion setup.</strong> Use your existing website tag when it matches the selected container. Configure the correct conversion event and test it with Google Tag Assistant before advertising.</li>
        <li><strong>Review your campaign and budget</strong> in Google Ads, then enable the campaign when you are ready.</li>
    </ol>
    <p>The website tags and conversion actions are in accounts you control. Removing SiteToSpend's access stops our ability to check or update them; it does not remove already published tags from your website.</p>
    <p><a href="{{ route('customers.gtm.setup', $customer) }}">Review your account and tracking checks</a></p>
@endsection
