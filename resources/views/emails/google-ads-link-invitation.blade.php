@extends('layouts.email')

@section('title', 'Approve access to your Google Ads account')

@section('content')
    <h1>One approval and we can get started</h1>

    <p>Hi {{ $customerName }},</p>

    <p>We've sent a request to manage your existing Google Ads account. It's waiting inside that account now &mdash; once you approve it, we can build and run your campaigns. Nothing changes until you do.</p>

    <div style="background:#f7fafc;border:1px solid #e8e5ef;border-radius:6px;padding:16px;margin:16px 0;">
        <p style="margin:0 0 4px;color:#6b7280;font-size:13px;">Sign in to this account to approve it</p>
        <p style="margin:0;font-size:22px;font-weight:700;letter-spacing:0.5px;">{{ $accountId }}</p>
    </div>

    {{-- The single most common reason people cannot find the request: they are
         signed into a different Google Ads account, or into a manager account,
         and a pending invitation only ever appears inside the invited one. --}}
    <div style="background:#fffbeb;border-left:4px solid #f59e0b;padding:14px 18px;margin:16px 0;border-radius:0 6px 6px 0;">
        <p style="margin:0;font-size:14px;color:#92400e;">
            <strong>Check which account you're signed in to first.</strong> The request only appears inside
            {{ $accountId }}. If you're signed in to a different Google Ads account &mdash; or to a manager
            account &mdash; you won't see it there. Use the account picker at the top right to switch.
        </p>
    </div>

    <p><strong>How to approve it</strong></p>

    <p style="margin:0 0 10px;">1. Go to <a href="https://ads.google.com">ads.google.com</a> and sign in.</p>
    <p style="margin:0 0 10px;">2. Check the account number at the top right reads <strong>{{ $accountId }}</strong>. If not, switch to it.</p>
    <p style="margin:0 0 10px;">3. Open <strong>Admin</strong> (bottom left), then <strong>Access and security</strong>, then the <strong>Managers</strong> tab.</p>
    <p style="margin:0 0 10px;">4. Find the pending request from <strong>{{ config('app.name') }}</strong> and choose <strong>Accept</strong>.</p>

    <p style="text-align:center;margin:28px 0;">
        <a href="https://ads.google.com" class="btn-primary">Open Google Ads</a>
    </p>

    {{-- Google itself sends nothing for a manager request, so if this email is
         missed there is no second prompt from anywhere. Worth saying plainly. --}}
    <p style="font-size:13px;color:#6b7280;">
        Google doesn't send its own notification for these requests, so this email is the only prompt you'll get.
        The request stays waiting until you accept it.
    </p>

    <p><strong>What this does and doesn't allow.</strong> Approving lets us create and optimise campaigns in your account. Your billing stays yours &mdash; spend continues to go directly to Google on your own payment method, and we never take a cut of it. You can remove our access at any time from the same screen.</p>

    <p>If you'd rather we walked you through it, just reply to this email.</p>

    <p>Thanks,<br>The {{ config('app.name') }} Team</p>
@endsection
