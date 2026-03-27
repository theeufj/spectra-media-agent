@extends('layouts.email')

@section('title', 'Your Ad Campaign Has Been Deployed!')

@section('content')
    <h1>Your Campaign is Live!</h1>
    <p>Hi {{ $user->name }},</p>
    <p>Great news! Your ad campaign, <strong>{{ $campaign->name }}</strong>, has been successfully deployed and is now running.</p>
    <p>You can monitor its performance and view all the details in your campaign dashboard.</p>
    <p style="text-align: center; margin-top: 30px; margin-bottom: 30px;">
        <a href="{{ route('campaigns.show', $campaign) }}" style="background: linear-gradient(135deg, #ff4d00 0%, #cc3d00 100%); color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: 700;">View Your Campaign</a>
    </p>
@endsection
