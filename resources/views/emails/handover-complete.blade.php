@extends('layouts.email')

@section('title', 'Your Google Ads account is ready')

@section('content')
    <h1>The keys are yours</h1>

    <p>
        Your Google Ads setup for {{ $customer->name }} is complete. The
        campaigns, keywords, ad copy and conversion tracking are all in place
        — and everything is <strong>paused</strong>, so nothing spends until
        you decide.
    </p>

    @if ($customer->google_ads_customer_id)
        <div style="background-color: #f7fafc; border-left: 3px solid {{ $tenantPrimary ?? '#ff4d00' }}; padding: 16px 20px; border-radius: 4px; margin: 24px 0;">
            <p style="margin: 0 0 4px; font-size: 13px; font-weight: bold; color: #718096;">Your Google Ads account ID</p>
            <p style="margin: 0; font-size: 18px; font-weight: 700; color: #2d3748;">{{ $customer->google_ads_customer_id }}</p>
        </div>
    @endif

    <p>Two steps between you and live ads:</p>

    <ol style="color: #4a5568; padding-left: 20px;">
        <li style="margin-bottom: 8px;"><strong>Add your billing</strong> — in Google Ads, go to Billing → Settings and add your payment method. Spend goes straight to Google; we're not in the middle.</li>
        <li style="margin-bottom: 8px;"><strong>Enable your campaigns</strong> — switch them from Paused to Enabled whenever you're ready.</li>
    </ol>

    <p>
        That's the whole engagement — no subscription, nothing recurring. If
        you ever want us to take the wheel again, you know where we are.
    </p>
@endsection
