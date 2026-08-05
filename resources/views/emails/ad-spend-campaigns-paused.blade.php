@extends('layouts.email')

@section('title', 'All Campaigns Have Been Paused')

@section('content')
    <h1>⛔ All Campaigns Have Been Paused</h1>

    <p>Hi there,</p>

    <p>Due to continued payment failures, we have <strong>paused all of your advertising campaigns</strong>.</p>

    <div style="background:#f7fafc;border:1px solid #e8e5ef;border-radius:6px;padding:16px;margin:16px 0;">
        <p style="margin:0;"><strong>Campaigns Paused At:</strong> {{ $credit->campaigns_paused_at->format('M j, Y g:i A') }}</p>
    </div>

    <h2>What This Means</h2>

    <ul>
        <li>Your ads are no longer running on Google or Facebook</li>
        <li>You are not being charged for ad spend</li>
        <li>Your campaign performance data is preserved</li>
    </ul>

    <h2>How To Resume</h2>

    <p>Simply update your payment method and your campaigns will be automatically resumed within 1 hour.</p>

    <p style="text-align:center;margin:24px 0;">
        <a href="{{ config('app.url') . '/billing' }}" class="btn-primary">Add Payment &amp; Resume Campaigns</a>
    </p>

    <h2>Need Help?</h2>

    <p>If you're experiencing financial difficulties or have questions about your account, please reply to this email. We're happy to work with you.</p>
@endsection
