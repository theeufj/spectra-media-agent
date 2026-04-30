@extends('layouts.email')

@section('title', ucfirst($report['period']['type'] ?? 'Weekly') . ' Executive Report')

@section('content')
    <h1 style="margin-top: 0;">{{ ucfirst($report['period']['type'] ?? 'Weekly') }} Executive Report</h1>
    <p>Hi {{ $user->name }},</p>
    <p>Here's your {{ $report['period']['type'] ?? 'weekly' }} performance summary for <strong>{{ $report['customer_name'] }}</strong> ({{ $report['period']['start'] }} — {{ $report['period']['end'] }}).</p>

    {{-- Summary Cards --}}
    <table width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0; border-collapse: collapse;">
        <tr>
            <td width="25%" style="padding: 14px; text-align: center; background: #f7fafc; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #718096; text-transform: uppercase;">Spend</div>
                <div style="font-size: 20px; font-weight: 700; color: #2d3748;">${{ number_format($report['summary']['total_cost'] ?? 0, 2) }}</div>
            </td>
            <td width="25%" style="padding: 14px; text-align: center; background: #f7fafc; border: 1px solid #e2e8f0; border-left: none;">
                <div style="font-size: 11px; color: #718096; text-transform: uppercase;">Clicks</div>
                <div style="font-size: 20px; font-weight: 700; color: #2d3748;">{{ number_format($report['summary']['total_clicks'] ?? 0) }}</div>
            </td>
            <td width="25%" style="padding: 14px; text-align: center; background: #f7fafc; border: 1px solid #e2e8f0; border-left: none;">
                <div style="font-size: 11px; color: #718096; text-transform: uppercase;">Conversions</div>
                <div style="font-size: 20px; font-weight: 700; color: #2d3748;">{{ number_format($report['summary']['total_conversions'] ?? 0, 1) }}</div>
            </td>
            <td width="25%" style="padding: 14px; text-align: center; background: #f7fafc; border: 1px solid #e2e8f0; border-left: none;">
                <div style="font-size: 11px; color: #718096; text-transform: uppercase;">CTR</div>
                <div style="font-size: 20px; font-weight: 700; color: #2d3748;">{{ $report['summary']['blended_ctr'] ?? 0 }}%</div>
            </td>
        </tr>
        <tr>
            <td width="25%" style="padding: 14px; text-align: center; background: #f7fafc; border: 1px solid #e2e8f0; border-top: none;">
                <div style="font-size: 11px; color: #718096; text-transform: uppercase;">Impressions</div>
                <div style="font-size: 20px; font-weight: 700; color: #2d3748;">{{ number_format($report['summary']['total_impressions'] ?? 0) }}</div>
            </td>
            <td width="25%" style="padding: 14px; text-align: center; background: #f7fafc; border: 1px solid #e2e8f0; border-top: none; border-left: none;">
                <div style="font-size: 11px; color: #718096; text-transform: uppercase;">Avg CPC</div>
                <div style="font-size: 20px; font-weight: 700; color: #2d3748;">${{ number_format($report['summary']['blended_cpc'] ?? 0, 2) }}</div>
            </td>
            <td width="25%" style="padding: 14px; text-align: center; background: #f7fafc; border: 1px solid #e2e8f0; border-top: none; border-left: none;">
                <div style="font-size: 11px; color: #718096; text-transform: uppercase;">CPA</div>
                <div style="font-size: 20px; font-weight: 700; color: #2d3748;">${{ number_format($report['summary']['blended_cpa'] ?? 0, 2) }}</div>
            </td>
            <td width="25%" style="padding: 14px; text-align: center; background: #f7fafc; border: 1px solid #e2e8f0; border-top: none; border-left: none;">
                <div style="font-size: 11px; color: #718096; text-transform: uppercase;">Campaigns</div>
                <div style="font-size: 20px; font-weight: 700; color: #2d3748;">{{ $report['summary']['total_campaigns'] ?? 0 }}</div>
            </td>
        </tr>
    </table>

    {{-- AI WoW Insights (bullet insights with cause + action) --}}
    @if(!empty($report['ai_insights']))
        <div style="background: #eff6ff; border-left: 4px solid #3b82f6; padding: 16px 20px; margin: 24px 0; border-radius: 0 6px 6px 0;">
            <div style="font-size: 13px; font-weight: 700; color: #1e40af; margin-bottom: 10px; text-transform: uppercase;">Week-on-Week Insights</div>
            <ul style="margin: 0; padding: 0; list-style: none;">
                @foreach($report['ai_insights'] as $insight)
                    <li style="font-size: 14px; color: #1e3a5f; line-height: 1.6; padding: 4px 0; border-bottom: 1px solid #dbeafe;">
                        {!! e($insight) !!}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- AI Executive Summary --}}
    @if(!empty($report['ai_executive_summary']))
        <div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 16px 20px; margin: 24px 0; border-radius: 0 6px 6px 0;">
            <div style="font-size: 13px; font-weight: 700; color: #92400e; margin-bottom: 8px; text-transform: uppercase;">AI Analysis</div>
            <div style="font-size: 14px; color: #3d4852; line-height: 1.7;">{!! nl2br(e($report['ai_executive_summary'])) !!}</div>
        </div>
    @endif

    {{-- Quality Score Insights --}}
    @if(!empty($report['keyword_insights']) && ($report['keyword_insights']['average_qs'] ?? null))
        <h2 style="font-size: 16px; color: #2d3748; margin-bottom: 12px;">Keyword Quality</h2>
        <table width="100%" cellpadding="8" cellspacing="0" style="margin-bottom: 24px; border-collapse: collapse; font-size: 13px;">
            <tr>
                <td style="padding: 10px; background: #f0fff4; border: 1px solid #c6f6d5;">
                    <strong>Avg QS:</strong> {{ number_format($report['keyword_insights']['average_qs'], 1) }}/10
                </td>
                <td style="padding: 10px; background: #f0fff4; border: 1px solid #c6f6d5; border-left: none;">
                    <strong>High QS (7+):</strong> {{ $report['keyword_insights']['high_qs_keywords'] }}
                </td>
                <td style="padding: 10px; background: #fff5f5; border: 1px solid #fed7d7; border-left: none;">
                    <strong>Low QS (&lt;5):</strong> {{ $report['keyword_insights']['low_qs_keywords'] }}
                </td>
            </tr>
        </table>
    @endif

    {{-- Attribution Summary --}}
    @if(!empty($report['attribution_summary']))
        <h2 style="font-size: 16px; color: #2d3748; margin-top: 32px; margin-bottom: 12px;">Attribution Overview</h2>
        <p style="font-size: 13px; color: #718096; margin-bottom: 12px;">Conversion credit by attribution model and platform for this period.</p>
        <table width="100%" cellpadding="6" cellspacing="0" style="border-collapse: collapse; font-size: 12px; margin-bottom: 24px;">
            <tr style="background: #edf2f7;">
                <th style="padding: 8px; border: 1px solid #e2e8f0; text-align: left;">Model</th>
                <th style="padding: 8px; border: 1px solid #e2e8f0; text-align: left;">Platform</th>
                <th style="padding: 8px; border: 1px solid #e2e8f0; text-align: right;">Conversions</th>
                <th style="padding: 8px; border: 1px solid #e2e8f0; text-align: right;">Value</th>
            </tr>
            @foreach($report['attribution_summary'] as $model => $platforms)
                @foreach($platforms as $platform => $data)
                    <tr>
                        <td style="padding: 7px 8px; border: 1px solid #e2e8f0; color: #4a5568;">{{ str_replace('_', ' ', ucfirst($model)) }}</td>
                        <td style="padding: 7px 8px; border: 1px solid #e2e8f0; color: #4a5568;">{{ ucfirst($platform) }}</td>
                        <td style="padding: 7px 8px; border: 1px solid #e2e8f0; text-align: right;">{{ number_format($data['conversions'] ?? 0) }}</td>
                        <td style="padding: 7px 8px; border: 1px solid #e2e8f0; text-align: right;">${{ number_format($data['value'] ?? 0, 2) }}</td>
                    </tr>
                @endforeach
            @endforeach
        </table>
    @endif

    <p style="text-align: center; margin-top: 30px; margin-bottom: 30px;">
        <a href="{{ url('/dashboard') }}" style="background: linear-gradient(135deg, #ff4d00 0%, #cc3d00 100%); color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: 700;">View Full Dashboard</a>
    </p>
@endsection
