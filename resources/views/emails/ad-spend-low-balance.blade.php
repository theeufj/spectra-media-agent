@extends('layouts.email')

@section('title', 'Low Ad Spend Balance')

@section('content')
    <h1>⚠️ Low Ad Spend Balance</h1>

    <p>Hi there,</p>

    <p>Your ad spend credit balance is running low. Based on your current spending rate, you have approximately <strong>{{ number_format($daysRemaining, 1) }} days</strong> of credit remaining.</p>

    <div style="background:#f7fafc;border:1px solid #e8e5ef;border-radius:6px;padding:16px;margin:16px 0;">
        <p style="margin:0 0 8px;"><strong>Current Balance:</strong> ${{ number_format($credit->current_balance, 2) }}</p>
        <p style="margin:0;"><strong>Average Daily Spend:</strong> ${{ number_format($credit->getAverageDailySpend(), 2) }}</p>
    </div>

    <h2>What Happens Next</h2>

    <p>We'll automatically attempt to replenish your credit balance using your payment method on file. If the automatic charge fails, you may experience campaign interruptions.</p>

    <p style="text-align:center;margin:24px 0;">
        <a href="{{ config('app.url') . '/billing' }}" class="btn-primary">Add Credit Now</a>
    </p>

    <h2>Why Am I Seeing This?</h2>

    <ul>
        <li>Your campaigns are spending faster than expected</li>
        <li>The automatic replenishment charge may have failed</li>
        <li>You may have multiple campaigns running</li>
    </ul>
@endsection
