@extends('layouts.email')

@section('title', 'Payment Still Failing - Budgets Reduced')

@section('content')
    <h1>🚨 Payment Still Failing - Budgets Reduced</h1>

    <p>Hi there,</p>

    <p>Your payment has failed for the second time. To protect your account, we've <strong>reduced your campaign budgets by 50%</strong>.</p>

    <div style="background:#f7fafc;border:1px solid #e8e5ef;border-radius:6px;padding:16px;margin:16px 0;">
        <p style="margin:0 0 8px;"><strong>Error:</strong> {{ $error }}</p>
        <p style="margin:0;"><strong>Current Balance:</strong> ${{ number_format($credit->current_balance, 2) }}</p>
    </div>

    <h2>Urgent Action Required</h2>

    <p>If we cannot successfully charge your payment method within the next <strong>24 hours</strong>, all your campaigns will be <strong>paused</strong>.</p>

    <p style="text-align:center;margin:24px 0;">
        <a href="{{ config('app.url') . '/billing' }}" class="btn-primary">Fix Payment Now</a>
    </p>

    <h2>Current Status:</h2>

    <table style="width:100%;border-collapse:collapse;margin:16px 0;">
        <thead>
            <tr>
                <th style="text-align:left;padding:8px 12px;border:1px solid #e8e5ef;background:#f7fafc;">Status</th>
                <th style="text-align:left;padding:8px 12px;border:1px solid #e8e5ef;background:#f7fafc;">Value</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="padding:8px 12px;border:1px solid #e8e5ef;">Budget Reduction</td>
                <td style="padding:8px 12px;border:1px solid #e8e5ef;">50%</td>
            </tr>
            <tr>
                <td style="padding:8px 12px;border:1px solid #e8e5ef;">Failed Attempts</td>
                <td style="padding:8px 12px;border:1px solid #e8e5ef;">{{ $credit->failed_charge_count }}</td>
            </tr>
            <tr>
                <td style="padding:8px 12px;border:1px solid #e8e5ef;">Time Until Pause</td>
                <td style="padding:8px 12px;border:1px solid #e8e5ef;">~24 hours</td>
            </tr>
        </tbody>
    </table>

    <p>Please update your payment method immediately to prevent campaign interruption.</p>
@endsection
