@extends('layouts.email')

@section('title', 'Your Collateral is Ready')

@section('content')
    <h1 style="margin-top: 0;">Your Collateral is Ready</h1>

    <p>Hi {{ $user->name }},</p>

    <p>We've finished generating the creative collateral for your campaign. Your images and videos are ready for review and deployment.</p>

    <div style="background: #fff5f0; border-left: 4px solid #ff4d00; padding: 20px; margin: 24px 0; border-radius: 4px;">
        <div style="font-size: 20px; font-weight: 600; color: #2d3748; margin-bottom: 8px;">{{ $campaign->name }}</div>
        <p style="color: #718096; margin: 0; font-size: 14px;">
            All strategies have been signed off and processed.
        </p>
        @if($campaign->strategies->isNotEmpty())
            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e2e8f0; text-align: center;">
                <div style="font-size: 24px; font-weight: 700; color: #ff4d00;">{{ $campaign->strategies->count() }}</div>
                <div style="font-size: 12px; color: #718096; text-transform: uppercase; margin-top: 5px;">{{ \Illuminate\Support\Str::plural('Strategy', $campaign->strategies->count()) }}</div>
            </div>
        @endif
    </div>

    <p><strong>What's next?</strong></p>
    <ul style="margin-top: 15px; padding-left: 20px;">
        <li style="margin-bottom: 10px;">Review the generated images and videos</li>
        <li style="margin-bottom: 10px;">Select your favorites for deployment</li>
        <li style="margin-bottom: 10px;">Request refinements if needed</li>
        <li>Deploy to your advertising platforms with one click</li>
    </ul>

    <p style="text-align: center; margin-top: 30px; margin-bottom: 30px;">
        @if($campaign->strategies->isNotEmpty())
            <a href="{{ ($tenantBaseUrl ?? url('')) }}{{ route('campaigns.collateral.show', ['campaign' => $campaign->id, 'strategy' => $campaign->strategies->first()->id], false) }}" class="btn-primary">View Your Collateral</a>
        @else
            <a href="{{ ($tenantBaseUrl ?? url('')) }}{{ route('campaigns.show', $campaign, false) }}" class="btn-primary">View Your Campaign</a>
        @endif
    </p>

    <p style="font-size: 14px; color: #718096;">
        If you have any questions or need assistance, our team is here to help.
    </p>
@endsection
