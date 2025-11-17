@extends('layouts.email')

@section('title', 'Your Videos Are Ready!')

@section('content')
    <h1>Your Videos Have Been Generated!</h1>
    <p>Hi {{ $user->name }},</p>
    <p>We've finished generating a new set of video assets for your campaign, <strong>{{ $campaign->name }}</strong>. They are now ready for your review and approval in your campaign dashboard.</p>
    <p>Reviewing them promptly will help keep your campaign on schedule.</p>
    <p style="text-align: center; margin-top: 30px; margin-bottom: 30px;">
        <a href="{{ route('campaigns.show', $campaign) }}" style="background-color: #4a5568; color: #ffffff; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold;">View Your New Videos</a>
    </p>
    <p>Best,<br>The CVSEEYOU Team</p>
@endsection
