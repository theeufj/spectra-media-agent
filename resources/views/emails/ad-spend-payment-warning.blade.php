@extends('layouts.email')

@section('title', 'Payment Failed - Action Required')

@section('content')
    <h1>⚠️ Payment Failed - Action Required</h1>

    <p>Hi there,</p>

    <p>We attempted to charge your payment method for your ad spend credit, but the payment failed.</p>

    <div style="background:#f7fafc;border:1px solid #e8e5ef;border-radius:6px;padding:16px;margin:16px 0;">
        <p style="margin:0 0 8px;"><strong>Error:</strong> {{ $error }}</p>
        <p style="margin:0;"><strong>Current Balance:</strong> ${{ number_format($credit->current_balance, 2) }}</p>
    </div>

    <h2>What happens next?</h2>

    <p>You have <strong>24 hours</strong> to update your payment method. If the payment still fails:</p>

    <ol>
        <li><strong>After 24 hours:</strong> Your campaign budgets will be reduced by 50%</li>
        <li><strong>After 48 hours:</strong> All campaigns will be paused</li>
    </ol>

    <p style="text-align:center;margin:24px 0;">
        <a href="{{ config('app.url') . '/billing' }}" class="btn-primary">Update Payment Method</a>
    </p>

    <h2>Tips to fix this:</h2>

    <ul>
        <li>Ensure your card hasn't expired</li>
        <li>Check that you have sufficient funds</li>
        <li>Contact your bank if the issue persists</li>
    </ul>

    <p>If you have any questions, please reply to this email.</p>
@endsection
