@extends('layouts.email')

@section('title', 'Your campaign is ready to deploy')

@section('content')
    <h1>Your campaign is ready to deploy</h1>

    <p>Hi {{ $user->name }},</p>

    <p>Your <strong>{{ $campaign->name }}</strong> campaign has been built and is ready for your review. We've generated {{ $totalAssets }} assets for you:</p>

    <div style="background:#f7fafc;border:1px solid #e8e5ef;border-radius:6px;padding:16px;margin:16px 0;">
        <p style="margin:0 0 8px;"><strong>{{ $campaign->strategies->sum('ad_copies_count') }}</strong> ad copy variations &mdash; headlines and descriptions optimized for click-through</p>
        <p style="margin:0 0 8px;"><strong>{{ $campaign->strategies->sum('image_collaterals_count') }}</strong> custom images &mdash; on-brand visuals for your ads</p>
        <p style="margin:0;"><strong>{{ $campaign->strategies->sum('video_collaterals_count') }}</strong> video ads &mdash; ready-to-run video content</p>
    </div>

    <p>Review the assets, connect your ad account, set a daily budget, and publish &mdash; the whole process takes just a few minutes.</p>

    <p style="text-align:center;margin:28px 0;">
        <a href="{{ route('campaigns.show', $campaign) }}" class="btn-primary">Review &amp; deploy your campaign</a>
    </p>

    <p>If you have any questions, just reply to this email and we'll help you get live.</p>

    <p>Thanks,<br>The {{ config('app.name') }} Team</p>
@endsection
