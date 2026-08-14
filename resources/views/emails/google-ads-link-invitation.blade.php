@extends('layouts.email')

@section('title', 'Approve access to your Google Ads account')

@section('content')
    <h1>One approval and we can get started</h1>

    <p>Hi {{ $customerName }},</p>

    <p>We've sent a request to manage your existing Google Ads account. It's waiting inside your account now &mdash; once you approve it, we can build and run your campaigns. Nothing changes until you do.</p>

    <div style="background:#f7fafc;border:1px solid #e8e5ef;border-radius:6px;padding:16px;margin:16px 0;">
        <p style="margin:0 0 4px;color:#6b7280;font-size:13px;">Account the request was sent to</p>
        <p style="margin:0;font-size:18px;font-weight:600;letter-spacing:0.5px;">{{ $accountId }}</p>
    </div>

    <p><strong>How to approve it</strong></p>

    <p style="margin:0 0 8px;">1. Sign in at <a href="https://ads.google.com">ads.google.com</a> with the email that owns this account.</p>
    <p style="margin:0 0 8px;">2. Go to <strong>Admin</strong>, then <strong>Access and security</strong>, then the <strong>Managers</strong> tab.</p>
    <p style="margin:0 0 8px;">3. Find the pending request from <strong>{{ config('app.name') }}</strong> and choose <strong>Accept</strong>.</p>

    <p style="text-align:center;margin:28px 0;">
        <a href="https://ads.google.com" class="btn-primary">Open Google Ads</a>
    </p>

    <p><strong>What this does and doesn't allow.</strong> Approving lets us create and optimise campaigns in your account. Your billing stays yours &mdash; spend continues to go directly to Google on your own payment method, and we never take a cut of it. You can remove our access at any time from the same screen.</p>

    <p>If you'd rather we walked you through it, just reply to this email.</p>

    <p>Thanks,<br>The {{ config('app.name') }} Team</p>
@endsection
