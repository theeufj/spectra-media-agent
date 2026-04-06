@extends('layouts.email')

@section('title', 'Monthly Performance Report')

@section('content')
    <h1 style="margin-top: 0;">Monthly Performance Report</h1>
    <p>Hi {{ $user->name }},</p>
    <p>Your monthly performance report for <strong>{{ $report['customer_name'] }}</strong> is attached as a PDF. Here's a quick summary for {{ $report['period']['start'] }} — {{ $report['period']['end'] }}.</p>

    {{-- Summary Cards --}}
    <table width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0; border-collapse: collapse;">
        <tr>
            <td width="25%" style="padding: 14px; text-align: center; background: #f7fafc; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #718096; text-transform: uppercase;">Spend</div>
                <div style="font-size: 20px; font-weight: 700; color: #2d3748;">${{ number_format($report['summary']['total_cost'] ?? 0, 2) }}</div>
                @if(!empty($report['prior_period']) && ($report['prior_period']['total_cost'] ?? 0) > 0)
                    @php $change = round(($report['summary']['total_cost'] - $report['prior_period']['total_cost']) / $report['prior_period']['total_cost'] * 100, 1); @endphp
                    <div style="font-size: 11px; color: {{ $change <= 0 ? '#38a169' : '#718096' }};">{{ $change > 0 ? '+' : '' }}{{ $change }}% MoM</div>
                @endif
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
                <div style="font-size: 11px; color: #718096; text-transform: uppercase;">CPA</div>
                <div style="font-size: 20px; font-weight: 700; color: #2d3748;">${{ number_format($report['summary']['blended_cpa'] ?? 0, 2) }}</div>
                @if(!empty($report['prior_period']) && ($report['prior_period']['blended_cpa'] ?? 0) > 0)
                    @php $change = round(($report['summary']['blended_cpa'] - $report['prior_period']['blended_cpa']) / $report['prior_period']['blended_cpa'] * 100, 1); @endphp
                    <div style="font-size: 11px; color: {{ $change < 0 ? '#38a169' : '#e53e3e' }};">{{ $change > 0 ? '+' : '' }}{{ $change }}% MoM</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- AI Executive Summary --}}
    @if(!empty($report['ai_executive_summary']))
        <div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 16px 20px; margin: 24px 0; border-radius: 0 6px 6px 0;">
            <div style="font-size: 13px; font-weight: 700; color: #92400e; margin-bottom: 8px; text-transform: uppercase;">AI Analysis</div>
            <div style="font-size: 14px; color: #3d4852; line-height: 1.7;">{!! nl2br(e($report['ai_executive_summary'])) !!}</div>
        </div>
    @endif

    {{-- Agent Activity Highlight --}}
    @if(!empty($report['agent_activity_summary']) && ($report['agent_activity_summary']['total_actions'] ?? 0) > 0)
        <div style="background: #f0fff4; border-left: 4px solid #38a169; padding: 16px 20px; margin: 24px 0; border-radius: 0 6px 6px 0;">
            <div style="font-size: 13px; font-weight: 700; color: #276749; margin-bottom: 8px; text-transform: uppercase;">
                Autonomous Agent Activity
            </div>
            <div style="font-size: 14px; color: #3d4852;">
                {{ $report['agent_activity_summary']['total_actions'] }} autonomous optimizations performed,
                {{ $report['agent_activity_summary']['completed'] }} completed successfully.
            </div>
        </div>
    @endif

    <p style="font-size: 13px; color: #718096; margin-top: 16px;">
        The full report with campaign breakdowns, keyword quality insights, and agent activity details is attached as a PDF.
    </p>

    <p style="text-align: center; margin-top: 30px; margin-bottom: 30px;">
        <a href="{{ url('/dashboard') }}" style="background: linear-gradient(135deg, #ff4d00 0%, #cc3d00 100%); color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: 700;">View Full Dashboard</a>
    </p>
@endsection
