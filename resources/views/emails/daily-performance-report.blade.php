@extends('layouts.email')

@section('title', 'Daily Performance Report')

@section('content')
    <h1 style="margin-top: 0;">Daily Performance Report</h1>
    <p>Hi {{ $user->name }},</p>
    <p>Here's your campaign performance summary for <strong>{{ $summary['date'] }}</strong>.</p>

    {{-- Combined Totals --}}
    <table width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0; border-collapse: collapse;">
        <tr>
            <td width="50%" style="padding: 16px; background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 6px 0 0 0;">
                <div style="font-size: 12px; color: #718096; text-transform: uppercase; letter-spacing: 0.5px;">Spend</div>
                <div style="font-size: 24px; font-weight: 700; color: #2d3748;">${{ number_format($summary['combined']['spend'], 2) }}</div>
                @if(($summary['changes']['spend'] ?? 0) != 0)
                    <div style="font-size: 12px; color: {{ $summary['changes']['spend'] > 0 ? '#e53e3e' : '#38a169' }};">
                        {{ $summary['changes']['spend'] > 0 ? '+' : '' }}{{ $summary['changes']['spend'] }}% vs prior day
                    </div>
                @endif
            </td>
            <td width="50%" style="padding: 16px; background: #f7fafc; border: 1px solid #e2e8f0; border-left: none; border-radius: 0 6px 0 0;">
                <div style="font-size: 12px; color: #718096; text-transform: uppercase; letter-spacing: 0.5px;">Conversions</div>
                <div style="font-size: 24px; font-weight: 700; color: #2d3748;">{{ number_format($summary['combined']['conversions'], 1) }}</div>
                @if(($summary['changes']['conversions'] ?? 0) != 0)
                    <div style="font-size: 12px; color: {{ $summary['changes']['conversions'] > 0 ? '#38a169' : '#e53e3e' }};">
                        {{ $summary['changes']['conversions'] > 0 ? '+' : '' }}{{ $summary['changes']['conversions'] }}% vs prior day
                    </div>
                @endif
            </td>
        </tr>
        <tr>
            <td width="50%" style="padding: 16px; background: #f7fafc; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 0 6px;">
                <div style="font-size: 12px; color: #718096; text-transform: uppercase; letter-spacing: 0.5px;">Clicks</div>
                <div style="font-size: 24px; font-weight: 700; color: #2d3748;">{{ number_format($summary['combined']['clicks']) }}</div>
                @if(($summary['changes']['clicks'] ?? 0) != 0)
                    <div style="font-size: 12px; color: {{ $summary['changes']['clicks'] > 0 ? '#38a169' : '#e53e3e' }};">
                        {{ $summary['changes']['clicks'] > 0 ? '+' : '' }}{{ $summary['changes']['clicks'] }}% vs prior day
                    </div>
                @endif
            </td>
            <td width="50%" style="padding: 16px; background: #f7fafc; border: 1px solid #e2e8f0; border-top: none; border-left: none; border-radius: 0 0 6px 0;">
                <div style="font-size: 12px; color: #718096; text-transform: uppercase; letter-spacing: 0.5px;">Impressions</div>
                <div style="font-size: 24px; font-weight: 700; color: #2d3748;">{{ number_format($summary['combined']['impressions']) }}</div>
                @if(($summary['changes']['impressions'] ?? 0) != 0)
                    <div style="font-size: 12px; color: {{ $summary['changes']['impressions'] > 0 ? '#38a169' : '#e53e3e' }};">
                        {{ $summary['changes']['impressions'] > 0 ? '+' : '' }}{{ $summary['changes']['impressions'] }}% vs prior day
                    </div>
                @endif
            </td>
        </tr>
    </table>

    {{-- Efficiency Metrics --}}
    <table width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 24px 0; border-collapse: collapse;">
        <tr>
            <td width="33%" style="padding: 12px; text-align: center; background: #fff5f5; border: 1px solid #fed7d7; border-radius: 6px 0 0 6px;">
                <div style="font-size: 11px; color: #718096; text-transform: uppercase;">CTR</div>
                <div style="font-size: 18px; font-weight: 700; color: #2d3748;">{{ $summary['combined']['ctr'] }}%</div>
            </td>
            <td width="34%" style="padding: 12px; text-align: center; background: #fff5f5; border: 1px solid #fed7d7; border-left: none;">
                <div style="font-size: 11px; color: #718096; text-transform: uppercase;">CPC</div>
                <div style="font-size: 18px; font-weight: 700; color: #2d3748;">${{ number_format($summary['combined']['cpc'], 2) }}</div>
            </td>
            <td width="33%" style="padding: 12px; text-align: center; background: #fff5f5; border: 1px solid #fed7d7; border-left: none; border-radius: 0 6px 6px 0;">
                <div style="font-size: 11px; color: #718096; text-transform: uppercase;">CPA</div>
                <div style="font-size: 18px; font-weight: 700; color: #2d3748;">${{ number_format($summary['combined']['cpa'], 2) }}</div>
            </td>
        </tr>
    </table>

    {{-- Platform Breakdown --}}
    @if($summary['google']['impressions'] > 0 || $summary['facebook']['impressions'] > 0)
        <h2 style="font-size: 16px; color: #2d3748; margin-bottom: 12px;">Platform Breakdown</h2>
        <table width="100%" cellpadding="8" cellspacing="0" style="margin-bottom: 24px; border-collapse: collapse; font-size: 13px;">
            <thead>
                <tr style="background: #edf2f7;">
                    <th style="text-align: left; padding: 10px; border-bottom: 2px solid #cbd5e0;">Platform</th>
                    <th style="text-align: right; padding: 10px; border-bottom: 2px solid #cbd5e0;">Spend</th>
                    <th style="text-align: right; padding: 10px; border-bottom: 2px solid #cbd5e0;">Clicks</th>
                    <th style="text-align: right; padding: 10px; border-bottom: 2px solid #cbd5e0;">Impr.</th>
                    <th style="text-align: right; padding: 10px; border-bottom: 2px solid #cbd5e0;">Conv.</th>
                </tr>
            </thead>
            <tbody>
                @if($summary['google']['impressions'] > 0)
                <tr>
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0;">Google Ads</td>
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;">${{ number_format($summary['google']['spend'], 2) }}</td>
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;">{{ number_format($summary['google']['clicks']) }}</td>
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;">{{ number_format($summary['google']['impressions']) }}</td>
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;">{{ number_format($summary['google']['conversions'], 1) }}</td>
                </tr>
                @endif
                @if($summary['facebook']['impressions'] > 0)
                <tr>
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0;">Facebook Ads</td>
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;">${{ number_format($summary['facebook']['spend'], 2) }}</td>
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;">{{ number_format($summary['facebook']['clicks']) }}</td>
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;">{{ number_format($summary['facebook']['impressions']) }}</td>
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;">{{ number_format($summary['facebook']['conversions'], 1) }}</td>
                </tr>
                @endif
            </tbody>
        </table>
    @endif

    {{-- Campaign Breakdown --}}
    @if(!empty($summary['campaigns']))
        <h2 style="font-size: 16px; color: #2d3748; margin-bottom: 12px;">Campaign Breakdown</h2>
        <table width="100%" cellpadding="8" cellspacing="0" style="margin-bottom: 24px; border-collapse: collapse; font-size: 13px;">
            <thead>
                <tr style="background: #edf2f7;">
                    <th style="text-align: left; padding: 10px; border-bottom: 2px solid #cbd5e0;">Campaign</th>
                    <th style="text-align: right; padding: 10px; border-bottom: 2px solid #cbd5e0;">Spend</th>
                    <th style="text-align: right; padding: 10px; border-bottom: 2px solid #cbd5e0;">Clicks</th>
                    <th style="text-align: right; padding: 10px; border-bottom: 2px solid #cbd5e0;">Conv.</th>
                </tr>
            </thead>
            <tbody>
                @foreach($summary['campaigns'] as $campaign)
                <tr>
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0;">
                        {{ $campaign['campaign_name'] }}
                        <span style="font-size: 11px; color: #a0aec0;">({{ $campaign['platform'] }})</span>
                    </td>
                    @php
                        $cSpend = ($campaign['google']['spend'] ?? 0) + ($campaign['facebook']['spend'] ?? 0);
                        $cClicks = ($campaign['google']['clicks'] ?? 0) + ($campaign['facebook']['clicks'] ?? 0);
                        $cConv = ($campaign['google']['conversions'] ?? 0) + ($campaign['facebook']['conversions'] ?? 0);
                    @endphp
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;">${{ number_format($cSpend, 2) }}</td>
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;">{{ number_format($cClicks) }}</td>
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;">{{ number_format($cConv, 1) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- What we optimised on the client's behalf --}}
    @php $opt = $summary['optimizations'] ?? ['applied' => [], 'pending' => 0]; @endphp
    @if(!empty($opt['applied']))
        <div style="margin: 24px 0; padding: 16px 20px; background: #f0fdf4; border-left: 4px solid #10b981; border-radius: 4px;">
            <div style="font-size: 11px; color: #047857; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 10px;">What we optimised for you</div>
            @foreach($opt['applied'] as $o)
                <div style="margin-bottom: 10px;">
                    <div style="font-size: 14px; color: #2d3748; font-weight: 600;">
                        <span style="color: #10b981;">&#10003;</span> {{ $o['label'] }}@if(!empty($o['campaign']))<span style="font-weight: 400; color: #a0aec0;"> — {{ $o['campaign'] }}</span>@endif
                    </div>
                    @if(!empty($o['rationale']))
                        <div style="font-size: 13px; color: #718096; margin-top: 2px; margin-left: 18px;">{{ $o['rationale'] }}</div>
                    @endif
                </div>
            @endforeach
            @if(($opt['pending'] ?? 0) > 0)
                <div style="font-size: 12px; color: #a0aec0; margin-top: 8px;">{{ $opt['pending'] }} further {{ \Illuminate\Support\Str::plural('recommendation', $opt['pending']) }} awaiting your review in the dashboard.</div>
            @endif
        </div>
    @elseif(($opt['pending'] ?? 0) > 0)
        <div style="margin: 24px 0; padding: 16px 20px; background: #f7fafc; border-left: 4px solid #cbd5e0; border-radius: 4px;">
            <div style="font-size: 14px; color: #2d3748; font-weight: 600;">{{ $opt['pending'] }} optimisation {{ \Illuminate\Support\Str::plural('recommendation', $opt['pending']) }} ready for your review</div>
            <div style="font-size: 13px; color: #718096; margin-top: 2px;">We've queued {{ $opt['pending'] > 1 ? 'these' : 'this' }} for your approval in the dashboard.</div>
        </div>
    @endif

    <p style="text-align: center; margin-top: 30px; margin-bottom: 30px;">
        <a href="{{ url('/dashboard') }}" style="background: linear-gradient(135deg, #ff4d00 0%, #cc3d00 100%); color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: 700;">View Dashboard</a>
    </p>

    <p style="text-align: center; font-size: 12px; color: #a0aec0; margin-top: 24px;">
        <a href="{{ URL::signedRoute('email.unsubscribe', ['user' => $user->id]) }}" style="color: #a0aec0;">Unsubscribe from performance reports</a>
    </p>
@endsection
