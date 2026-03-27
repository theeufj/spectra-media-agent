@extends('layouts.email')

@section('title', 'Your Videos Are Ready!')

@section('content')
    <h1>Your Videos Have Been Generated!</h1>
    <p>Hi {{ $user->name }},</p>
    <p>We've finished generating a new set of video assets for your campaign, <strong>{{ $campaign->name }}</strong>. They are now ready for your review and approval in your campaign dashboard.</p>
    <p>Reviewing them promptly will help keep your campaign on schedule.</p>
    <p style="text-align: center; margin-top: 30px; margin-bottom: 30px;">
        <a href="{{ route('campaigns.show', $campaign) }}" style="background: linear-gradient(135deg, #ff4d00 0%, #cc3d00 100%); color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: 700;">View Your New Videos</a>
    </p>
@endsection
