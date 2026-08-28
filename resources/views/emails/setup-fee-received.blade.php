@extends('layouts.email')

@section('title', 'Your Google Ads setup is underway')

@section('content')
    <h1>Payment received, {{ $userName }} — we're on it</h1>

    <p>
        Thanks for choosing the one-time setup for {{ $customer->name }}.
        Here's exactly what happens now:
    </p>

    <ol style="color: #4a5568; padding-left: 20px;">
        <li style="margin-bottom: 8px;"><strong>We build your campaigns</strong> — written from your brand profile: ad copy, keywords, imagery and structure.</li>
        <li style="margin-bottom: 8px;"><strong>We set up conversion tracking</strong> — so every lead and sale is counted from the first click.</li>
        <li style="margin-bottom: 8px;"><strong>Everything arrives paused</strong> — nothing spends until you switch it on.</li>
        <li style="margin-bottom: 8px;"><strong>We hand you the keys</strong> — the account is yours: you add your own Google Ads billing, press go, and never need us again (though we're here if you do).</li>
    </ol>

    <p>
        We'll email you at each step. There's nothing you need to do right now.
    </p>

    <p style="font-size: 13px; color: #718096;">
        Your receipt is issued by Stripe and arrives separately. The setup fee is one-time — there is no subscription and nothing recurring.
    </p>
@endsection
