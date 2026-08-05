@extends('layouts.email')

@section('title', 'Verify your Google Ads account')

@section('content')
    <h1>Verify your Google Ads account</h1>

    <p>Hi {{ $user->name }},</p>

    <p>Your Google Ads account is set up and ready, but Google requires identity verification before your campaigns can go live. This is a standard requirement for all new advertising accounts.</p>

    <div style="background:#f7fafc;border:1px solid #e8e5ef;border-radius:6px;padding:16px;margin:16px 0;">
        <p style="margin:0;"><strong>Account:</strong> {{ $customer->name }}@if($customer->google_ads_customer_id) (ID: {{ $customer->google_ads_customer_id }})@endif</p>
        @if($campaign)
        <p style="margin:8px 0 0;"><strong>Campaign:</strong> {{ $campaign->name }}</p>
        @endif
    </div>

    <p>Completing verification takes just a few minutes:</p>
    <ol>
        <li>Open Google's verification portal using the button below</li>
        <li>Sign in with the Google account linked to your ads account</li>
        <li>Submit your business identity documents as requested</li>
        <li>Google typically reviews submissions within 3&ndash;5 business days</li>
    </ol>

    <p style="text-align:center;margin:28px 0;">
        <a href="https://ads.google.com/aw/businessidentity" class="btn-primary">Complete Google Ads verification</a>
    </p>

    <p>Once Google approves your verification, your campaigns will deploy automatically on your next publish &mdash; nothing further is needed on our end.</p>

    <p>If you have any questions or need a hand, just reply to this email.</p>

    <p>Thanks,<br>The {{ config('app.name') }} Team</p>
@endsection
