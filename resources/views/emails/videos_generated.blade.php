@extends('layouts.email')

@section('title', 'Your Videos Are Ready!')

@section('content')
    <h1>{{ $videoCount === 1 ? 'Your Video Is Ready!' : "Your {$videoCount} Videos Are Ready!" }}</h1>
    <p>Hi {{ $user->name }},</p>
    <p>
        We've finished generating
        @if ($videoCount === 1)
            <strong>1 new video</strong>
        @else
            <strong>{{ $videoCount }} new videos</strong>
        @endif
        for your campaign, <strong>{{ $campaign->name }}</strong>.
        They're ready to review and approve right now.
    </p>
    <p style="text-align: center; margin-top: 30px; margin-bottom: 30px;">
        <a href="{{ route('campaigns.show', $campaign) }}?tab=collateral" style="background: linear-gradient(135deg, #ff4d00 0%, #cc3d00 100%); color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: 700;">Review Your Videos</a>
    </p>
    <p style="color: #888; font-size: 13px; text-align: center;">
        ⏳ Videos are stored for <strong>30 days</strong> — review them before they expire.
    </p>
@endsection
