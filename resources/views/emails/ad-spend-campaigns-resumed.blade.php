@extends('layouts.email')

@section('title', 'Payment Received - Campaigns Resumed!')

@section('content')
    <h1>✅ Payment Received - Campaigns Resumed!</h1>

    <p>Hi there,</p>

    <p>Great news! Your payment has been successfully processed and <strong>all your campaigns are now running again</strong>.</p>

    <div style="background:#f7fafc;border:1px solid #e8e5ef;border-radius:6px;padding:16px;margin:16px 0;">
        <p style="margin:0;"><strong>New Credit Balance:</strong> ${{ number_format($credit->current_balance, 2) }}</p>
    </div>

    <h2>What Happened</h2>

    <ul>
        <li>Payment was successfully processed</li>
        <li>Campaign budgets restored to 100%</li>
        <li>All paused campaigns have been resumed</li>
    </ul>

    <h2>Your Campaigns</h2>

    <p>Your ads should begin serving within the next 30-60 minutes as the ad networks re-enable them.</p>

    <p style="text-align:center;margin:24px 0;">
        <a href="{{ config('app.url') . '/campaigns' }}" class="btn-primary">View Your Campaigns</a>
    </p>

    <p>Thank you for resolving this promptly. If you have any questions, please don't hesitate to reach out.</p>
@endsection
