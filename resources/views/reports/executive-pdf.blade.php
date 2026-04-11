<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ ucfirst($report['period']['type']) }} Performance Report — {{ $report['customer_name'] }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #1f2937;
            background: #ffffff;
            font-size: 13px;
        }

        /* Brand Color Variables — overridden when white-label branding is applied */
        :root {
            --primary: {{ $branding['primary_color'] ?? '#ff4d00' }};
            --primary-dark: {{ $branding['primary_dark'] ?? '#992e00' }};
            --primary-darkest: {{ $branding['primary_darkest'] ?? '#330f00' }};
        }

        /* Cover Page */
        .cover {
            height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background: linear-gradient(135deg, var(--primary-darkest) 0%, var(--primary-dark) 40%, var(--primary) 100%);
            color: #ffffff;
            text-align: center;
            padding: 60px;
            page-break-after: always;
        }

        .cover-logo {
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 3px;
            text-transform: uppercase;
            opacity: 0.8;
            margin-bottom: 40px;
        }

        .cover-logo img {
            max-height: 48px;
            max-width: 200px;
        }

        .cover h1 {
            font-size: 36px;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 12px;
        }

        .cover .subtitle {
            font-size: 18px;
            opacity: 0.85;
            margin-bottom: 40px;
        }

        .cover .meta {
            font-size: 14px;
            opacity: 0.7;
        }

        /* Content pages */
        .page {
            padding: 50px;
            page-break-inside: avoid;
        }

        .section {
            margin-bottom: 32px;
        }

        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary-darkest);
            margin-bottom: 14px;
            padding-bottom: 6px;
            border-bottom: 2px solid var(--primary);
        }

        /* Metrics Grid */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 24px;
        }

        .metric-card {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px;
            text-align: center;
        }

        .metric-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            margin-bottom: 4px;
        }

        .metric-value {
            font-size: 22px;
            font-weight: 700;
            color: #1f2937;
        }

        .metric-change {
            font-size: 11px;
            margin-top: 4px;
        }

        .metric-change.positive { color: #059669; }
        .metric-change.negative { color: #dc2626; }
        .metric-change.neutral { color: #6b7280; }

        /* Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            font-size: 12px;
        }

        .data-table th {
            background: #f3f4f6;
            padding: 10px 12px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
        }

        .data-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #f3f4f6;
            color: #4b5563;
        }

        .data-table tr:hover td {
            background: #f9fafb;
        }

        /* AI Summary Box */
        .ai-summary {
            background: #fffbeb;
            border-left: 4px solid var(--primary);
            border-radius: 0 8px 8px 0;
            padding: 20px 24px;
            margin-bottom: 24px;
        }

        .ai-summary-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--primary-dark);
            margin-bottom: 10px;
        }

        .ai-summary-text {
            font-size: 13px;
            color: #374151;
            line-height: 1.7;
            white-space: pre-line;
        }

        /* Agent Activity Box */
        .agent-card {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            padding: 14px 18px;
            margin-bottom: 10px;
        }

        .agent-card-title {
            font-size: 13px;
            font-weight: 700;
            color: #166534;
            margin-bottom: 6px;
        }

        .agent-action {
            font-size: 12px;
            color: #374151;
            padding-left: 12px;
            border-left: 2px solid #86efac;
            margin-bottom: 4px;
        }

        /* Platform Badges */
        .platform-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .platform-google { background: #dbeafe; color: #1d4ed8; }
        .platform-facebook { background: #ede9fe; color: #6d28d9; }

        /* Footer */
        .page-footer {
            position: fixed;
            bottom: 20px;
            left: 50px;
            right: 50px;
            font-size: 10px;
            color: #9ca3af;
            border-top: 1px solid #e5e7eb;
            padding-top: 8px;
            display: flex;
            justify-content: space-between;
        }
    </style>
</head>
<body>
    {{-- Cover Page --}}
    <div class="cover">
        <div class="cover-logo">
            @if(!empty($branding['logo_url']))
                <img src="{{ $branding['logo_url'] }}" alt="{{ $branding['company_name'] ?? $report['customer_name'] }}">
            @else
                SITE TO SPEND
            @endif
        </div>
        <h1>{{ ucfirst($report['period']['type']) }} Performance Report</h1>
        <div class="subtitle">{{ $report['customer_name'] }}</div>
        <div class="meta">
            {{ \Carbon\Carbon::parse($report['period']['start'])->format('M j, Y') }}
            — {{ \Carbon\Carbon::parse($report['period']['end'])->format('M j, Y') }}
        </div>
    </div>

    {{-- Performance Overview --}}
    <div class="page">
        <div class="section">
            <h2 class="section-title">Performance Overview</h2>

            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-label">Total Spend</div>
                    <div class="metric-value">${{ number_format($report['summary']['total_cost'] ?? 0, 2) }}</div>
                    @if(!empty($report['prior_period']))
                        @php
                            $change = $report['prior_period']['total_cost'] > 0
                                ? round(($report['summary']['total_cost'] - $report['prior_period']['total_cost']) / $report['prior_period']['total_cost'] * 100, 1)
                                : 0;
                        @endphp
                        <div class="metric-change {{ $change > 0 ? 'neutral' : ($change < 0 ? 'positive' : 'neutral') }}">
                            {{ $change > 0 ? '+' : '' }}{{ $change }}% vs prior period
                        </div>
                    @endif
                </div>

                <div class="metric-card">
                    <div class="metric-label">Clicks</div>
                    <div class="metric-value">{{ number_format($report['summary']['total_clicks'] ?? 0) }}</div>
                    @if(!empty($report['prior_period']))
                        @php
                            $change = $report['prior_period']['total_clicks'] > 0
                                ? round(($report['summary']['total_clicks'] - $report['prior_period']['total_clicks']) / $report['prior_period']['total_clicks'] * 100, 1)
                                : 0;
                        @endphp
                        <div class="metric-change {{ $change > 0 ? 'positive' : ($change < 0 ? 'negative' : 'neutral') }}">
                            {{ $change > 0 ? '+' : '' }}{{ $change }}% vs prior period
                        </div>
                    @endif
                </div>

                <div class="metric-card">
                    <div class="metric-label">Conversions</div>
                    <div class="metric-value">{{ number_format($report['summary']['total_conversions'] ?? 0, 1) }}</div>
                    @if(!empty($report['prior_period']))
                        @php
                            $change = $report['prior_period']['total_conversions'] > 0
                                ? round(($report['summary']['total_conversions'] - $report['prior_period']['total_conversions']) / $report['prior_period']['total_conversions'] * 100, 1)
                                : 0;
                        @endphp
                        <div class="metric-change {{ $change > 0 ? 'positive' : ($change < 0 ? 'negative' : 'neutral') }}">
                            {{ $change > 0 ? '+' : '' }}{{ $change }}% vs prior period
                        </div>
                    @endif
                </div>

                <div class="metric-card">
                    <div class="metric-label">CPA</div>
                    <div class="metric-value">${{ number_format($report['summary']['blended_cpa'] ?? 0, 2) }}</div>
                    @if(!empty($report['prior_period']))
                        @php
                            $change = $report['prior_period']['blended_cpa'] > 0
                                ? round(($report['summary']['blended_cpa'] - $report['prior_period']['blended_cpa']) / $report['prior_period']['blended_cpa'] * 100, 1)
                                : 0;
                        @endphp
                        <div class="metric-change {{ $change < 0 ? 'positive' : ($change > 0 ? 'negative' : 'neutral') }}">
                            {{ $change > 0 ? '+' : '' }}{{ $change }}% vs prior period
                        </div>
                    @endif
                </div>
            </div>

            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-label">Impressions</div>
                    <div class="metric-value">{{ number_format($report['summary']['total_impressions'] ?? 0) }}</div>
                </div>
                <div class="metric-card">
                    <div class="metric-label">CTR</div>
                    <div class="metric-value">{{ $report['summary']['blended_ctr'] ?? 0 }}%</div>
                </div>
                <div class="metric-card">
                    <div class="metric-label">Avg CPC</div>
                    <div class="metric-value">${{ number_format($report['summary']['blended_cpc'] ?? 0, 2) }}</div>
                </div>
                <div class="metric-card">
                    <div class="metric-label">Campaigns</div>
                    <div class="metric-value">{{ $report['summary']['total_campaigns'] ?? 0 }}</div>
                </div>
            </div>
        </div>

        {{-- AI Executive Summary --}}
        @if(!empty($report['ai_executive_summary']))
            <div class="section">
                <div class="ai-summary">
                    <div class="ai-summary-title">AI Executive Summary</div>
                    <div class="ai-summary-text">{!! nl2br(e($report['ai_executive_summary'])) !!}</div>
                </div>
            </div>
        @endif
    </div>

    {{-- Platform Breakdowns --}}
    <div class="page">
        @if(!empty($report['campaigns']))
            <div class="section">
                <h2 class="section-title">
                    <span class="platform-badge platform-google">Google Ads</span>
                    Campaign Performance
                </h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Campaign</th>
                            <th>Impressions</th>
                            <th>Clicks</th>
                            <th>CTR</th>
                            <th>Spend</th>
                            <th>Conversions</th>
                            <th>CPA</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($report['campaigns'] as $campaign)
                            <tr>
                                <td>{{ $campaign['campaign_name'] ?? 'Unknown' }}</td>
                                <td>{{ number_format($campaign['impressions'] ?? 0) }}</td>
                                <td>{{ number_format($campaign['clicks'] ?? 0) }}</td>
                                <td>{{ $campaign['ctr'] ?? 0 }}%</td>
                                <td>${{ number_format(($campaign['cost_micros'] ?? 0) / 1_000_000, 2) }}</td>
                                <td>{{ number_format($campaign['conversions'] ?? 0, 1) }}</td>
                                <td>${{ ($campaign['conversions'] ?? 0) > 0 ? number_format(($campaign['cost_micros'] ?? 0) / 1_000_000 / $campaign['conversions'], 2) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if(!empty($report['facebook_campaigns']))
            <div class="section">
                <h2 class="section-title">
                    <span class="platform-badge platform-facebook">Facebook</span>
                    Campaign Performance
                </h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Campaign</th>
                            <th>Impressions</th>
                            <th>Clicks</th>
                            <th>Spend</th>
                            <th>Conversions</th>
                            <th>Reach</th>
                            <th>CPA</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($report['facebook_campaigns'] as $campaign)
                            <tr>
                                <td>{{ $campaign['campaign_name'] ?? 'Unknown' }}</td>
                                <td>{{ number_format($campaign['impressions'] ?? 0) }}</td>
                                <td>{{ number_format($campaign['clicks'] ?? 0) }}</td>
                                <td>${{ number_format($campaign['cost'] ?? 0, 2) }}</td>
                                <td>{{ number_format($campaign['conversions'] ?? 0, 1) }}</td>
                                <td>{{ number_format($campaign['reach'] ?? 0) }}</td>
                                <td>${{ number_format($campaign['cpa'] ?? 0, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Keyword Quality + Agent Activity --}}
    <div class="page">
        @if(!empty($report['keyword_insights']) && ($report['keyword_insights']['average_qs'] ?? null))
            <div class="section">
                <h2 class="section-title">Keyword Quality Insights</h2>
                <div class="metrics-grid" style="grid-template-columns: repeat(3, 1fr);">
                    <div class="metric-card">
                        <div class="metric-label">Avg Quality Score</div>
                        <div class="metric-value">{{ number_format($report['keyword_insights']['average_qs'], 1) }}/10</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-label">High QS (7+)</div>
                        <div class="metric-value">{{ $report['keyword_insights']['high_qs_keywords'] }}</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-label">Low QS (&lt;5)</div>
                        <div class="metric-value">{{ $report['keyword_insights']['low_qs_keywords'] }}</div>
                    </div>
                </div>

                @if(!empty($report['keyword_insights']['top_keywords']))
                    <table class="data-table">
                        <thead>
                            <tr><th>Top Keyword</th><th>Quality Score</th><th>Impressions</th></tr>
                        </thead>
                        <tbody>
                            @foreach($report['keyword_insights']['top_keywords'] as $kw)
                                <tr>
                                    <td>{{ $kw['keyword'] }}</td>
                                    <td>{{ $kw['quality_score'] }}/10</td>
                                    <td>{{ number_format($kw['impressions'] ?? 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endif

        @if(!empty($report['agent_activity_summary']) && $report['agent_activity_summary']['total_actions'] > 0)
            <div class="section">
                <h2 class="section-title">Autonomous Agent Activity</h2>
                <p style="margin-bottom: 14px; color: #6b7280;">
                    {{ $report['agent_activity_summary']['total_actions'] }} autonomous actions executed,
                    {{ $report['agent_activity_summary']['completed'] }} completed successfully.
                </p>

                @foreach($report['agent_activity_summary']['by_agent'] ?? [] as $agent)
                    <div class="agent-card">
                        <div class="agent-card-title">{{ $agent['agent'] }} — {{ $agent['total_actions'] }} actions</div>
                        @foreach($agent['key_actions'] as $action)
                            <div class="agent-action">{{ $action['description'] }}</div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Footer --}}
    <div class="page-footer">
        <span>Generated {{ now()->format('M j, Y g:i A') }}</span>
        <span>{{ $branding['company_name'] ?? 'Site to Spend' }} — Autonomous Advertising Platform</span>
    </div>
</body>
</html>
