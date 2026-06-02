@extends('layouts.email')

@section('title', 'You have been invited!')

@section('content')
    <h1>You've Been Invited!</h1>
    <p>You've been invited to join <strong>{{ $invitation->customer->name }}</strong> on Site to Spend as a <strong>{{ $invitation->role }}</strong>.</p>

    <p>As a {{ $invitation->role }}, you'll be able to:</p>
    <ul>
        <li>View campaign performance across Google, Facebook, and more</li>
        <li>Review and approve AI-generated ad creatives</li>
        <li>Monitor spend, conversions, and optimisation recommendations</li>
    </ul>

    <p style="text-align: center; margin-top: 30px; margin-bottom: 30px;">
        <a href="{{ route('invitations.accept', $invitation->token) }}" style="background: linear-gradient(135deg, #ff4d00 0%, #cc3d00 100%); color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: 700;">Accept Invitation</a>
    </p>

    <p style="color: #e53e3e; font-size: 13px; text-align: center;">
        ⏰ This invitation expires in <strong>7 days</strong>. Accept it before it does.
    </p>

    <p style="font-size: 12px; color: #a0aec0; text-align: center; margin-top: 16px;">
        If you weren't expecting this, you can safely ignore this email.
    </p>
@endsection
