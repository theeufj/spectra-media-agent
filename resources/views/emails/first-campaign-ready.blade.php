@extends('layouts.email')

@section('title', 'Your first campaign is ready')

@section('content')
    <h1>We built your first campaign, {{ $userName }}</h1>

    <p>
        We read {{ $campaign->customer->website ?: 'your website' }} and put together a campaign
        for {{ $campaign->customer->name }}. Nothing is live and nothing has been charged —
        it's yours to look over, change, or ignore.
    </p>

    <div style="background-color: #f7fafc; border-left: 3px solid #ff4d00; padding: 20px; border-radius: 4px; margin: 24px 0;">
        <h2 style="font-size: 18px; color: #2d3748; margin: 0 0 12px;">{{ $campaign->name }}</h2>

        @if($campaign->goals)
            <p style="margin: 0 0 12px; color: #4a5568;">{{ $campaign->goals }}</p>
        @endif

        @if($campaign->target_market)
            <p style="margin: 0 0 4px; font-size: 13px; font-weight: bold; color: #718096;">Who it targets</p>
            <p style="margin: 0 0 12px; color: #4a5568;">{{ $campaign->target_market }}</p>
        @endif

        @if($campaign->primary_kpi)
            <p style="margin: 0 0 4px; font-size: 13px; font-weight: bold; color: #718096;">What we'd measure</p>
            <p style="margin: 0; color: #4a5568;">{{ $campaign->primary_kpi }}</p>
        @endif
    </div>

    {{-- The budget is the one number with consequences, so it is stated plainly
         rather than buried, and framed as a suggestion because that is what it is. --}}
    <h2 style="font-size: 16px; color: #2d3748;">The budget is a suggestion</h2>
    <p>
        We've pencilled in <strong>{{ $campaign->customer->currency_code ?? 'USD' }}
        {{ number_format((float) $campaign->daily_budget, 2) }} a day</strong>.
        @if($campaign->budget_rationale)
            {{ $campaign->budget_rationale }}
        @endif
    </p>
    <p>
        You can change it to anything you like when you review. We ask you to confirm the
        figure before anything goes live, because the first seven days are charged up front.
    </p>

    <p style="text-align: center; margin: 32px 0;">
        <a href="{{ ($tenantBaseUrl ?? url('')) }}{{ route('campaigns.show', $campaign->id, false) }}"
           style="background: linear-gradient(135deg, #ff4d00 0%, #cc3d00 100%); color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: 700;">
            Review your campaign
        </a>
    </p>

    <p style="font-size: 13px; color: #718096;">
        Not what you had in mind? Edit anything, or delete it and start fresh — it costs nothing either way.
    </p>
@endsection
