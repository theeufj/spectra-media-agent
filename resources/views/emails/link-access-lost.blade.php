@extends('layouts.email')

@section('title', 'We lost access to your Google Ads account')

@section('content')
    <h1>Our access to your Google Ads account was removed</h1>

    <p>
        As of today we can no longer manage the Google Ads account for
        {{ $customer->name }} ({{ $customer->google_ads_customer_id }}).
        If that was intentional, no hard feelings — but you should know
        what stops happening from tonight:
    </p>

    <ul style="color: #4a5568; padding-left: 20px;">
        <li style="margin-bottom: 6px;">No more nightly checks that fix disapproved ads before they cost you impressions</li>
        <li style="margin-bottom: 6px;">No new negative keywords — wasted-spend terms accumulate from here</li>
        <li style="margin-bottom: 6px;">No budget pacing — spend stops following when your customers are actually online</li>
        <li style="margin-bottom: 6px;">No creative testing — your ads stop improving with the data they earn</li>
    </ul>

    <p>
        Campaigns don't fail when management stops. They decay — quietly, a
        little each week.
    </p>

    <p>
        If removing us wasn't intentional (agency changes and permission
        clean-ups catch people out), re-accepting the manager invitation
        restores everything — just reply to this email and we'll resend it.
    </p>

    <p style="font-size: 13px; color: #718096;">
        If you're moving on: campaigns we built within the first
        {{ config('billing.early_exit.minimum_months', 3) }} months fall
        under the one-time setup terms in our Terms of Service.
    </p>
@endsection
