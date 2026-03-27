<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advertising Proposal — {{ $proposal->client_name }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #1f2937;
            background: #ffffff;
        }

        /* Cover Page */
        .cover {
            height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background: linear-gradient(135deg, #330f00 0%, #992e00 40%, #ff4d00 100%);
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

        .cover h1 {
            font-size: 42px;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 16px;
        }

        .cover .subtitle {
            font-size: 20px;
            opacity: 0.85;
            margin-bottom: 40px;
        }

        .cover .meta {
            font-size: 14px;
            opacity: 0.7;
        }

        .cover-hero {
            width: 100%;
            max-width: 500px;
            border-radius: 12px;
            margin-bottom: 40px;
        }

        /* Content pages */
        .page {
            padding: 50px;
            page-break-inside: avoid;
        }

        .section {
            margin-bottom: 36px;
        }

        .section-title {
            font-size: 24px;
            font-weight: 700;
            color: #330f00;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid #ffdbcc;
        }

        .section-text {
            font-size: 14px;
            line-height: 1.7;
            color: #374151;
            white-space: pre-line;
        }

        /* Platform Strategy Cards */
        .platform-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            margin-bottom: 24px;
            overflow: hidden;
            page-break-inside: avoid;
        }

        .platform-header {
            background: linear-gradient(135deg, #992e00 0%, #ff4d00 100%);
            color: #ffffff;
            padding: 16px 24px;
            font-size: 18px;
            font-weight: 700;
        }

        .platform-body {
            padding: 24px;
        }

        .platform-body p {
            font-size: 13px;
            color: #374151;
            margin-bottom: 12px;
            line-height: 1.6;
        }

        .platform-body h4 {
            font-size: 14px;
            font-weight: 600;
            color: #330f00;
            margin-bottom: 6px;
            margin-top: 16px;
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 16px;
        }

        .metric-box {
            background: #f0f0ff;
            border-radius: 8px;
            padding: 12px;
            text-align: center;
        }

        .metric-label {
            font-size: 11px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .metric-value {
            font-size: 16px;
            font-weight: 700;
            color: #330f00;
            margin-top: 4px;
        }

        .budget-badge {
            display: inline-block;
            background: #dbeafe;
            color: #1e40af;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            margin-top: 8px;
        }

        /* Campaign types */
        .tag-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 8px;
            margin-bottom: 12px;
        }

        .tag {
            background: #ffdbcc;
            color: #661f00;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 12px;
        }

        /* Sample Ad Concepts */
        .ad-concept {
            background: #fafafa;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 10px;
        }

        .ad-concept .headline {
            font-size: 14px;
            font-weight: 700;
            color: #1e40af;
        }

        .ad-concept .desc {
            font-size: 12px;
            color: #4b5563;
            margin-top: 4px;
        }

        .ad-concept .cta {
            font-size: 11px;
            color: #ff4d00;
            font-weight: 600;
            margin-top: 6px;
        }

        /* Timeline */
        .timeline-item {
            display: flex;
            margin-bottom: 20px;
            page-break-inside: avoid;
        }

        .timeline-marker {
            flex-shrink: 0;
            width: 40px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .timeline-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #ff4d00;
        }

        .timeline-line {
            width: 2px;
            flex: 1;
            background: #ffb899;
            margin-top: 4px;
        }

        .timeline-content {
            flex: 1;
            padding-left: 12px;
        }

        .timeline-content h4 {
            font-size: 15px;
            font-weight: 700;
            color: #330f00;
        }

        .timeline-content .duration {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 6px;
        }

        .timeline-content li {
            font-size: 13px;
            color: #374151;
            margin-left: 16px;
            margin-bottom: 3px;
        }

        /* Projected Results */
        .results-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-top: 16px;
        }

        .result-card {
            background: linear-gradient(135deg, #ffede5 0%, #ffdbcc 100%);
            border-radius: 10px;
            padding: 20px;
            text-align: center;
        }

        .result-card h4 {
            font-size: 14px;
            font-weight: 700;
            color: #992e00;
            margin-bottom: 8px;
        }

        .result-card p {
            font-size: 12px;
            color: #374151;
            line-height: 1.5;
        }

        /* Investment Summary */
        .investment-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }

        .investment-table th,
        .investment-table td {
            padding: 12px 16px;
            text-align: left;
            font-size: 14px;
            border-bottom: 1px solid #e5e7eb;
        }

        .investment-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
        }

        .investment-table td.value {
            text-align: right;
            font-weight: 700;
            color: #330f00;
        }

        .investment-table tr.total {
            background: #ffede5;
        }

        .investment-table tr.total td {
            font-size: 16px;
            font-weight: 800;
            border-bottom: none;
        }

        /* Why Us */
        .why-us-list {
            list-style: none;
            padding: 0;
        }

        .why-us-list li {
            font-size: 14px;
            color: #374151;
            padding: 10px 0 10px 32px;
            border-bottom: 1px solid #f3f4f6;
            position: relative;
        }

        .why-us-list li::before {
            content: '✓';
            position: absolute;
            left: 0;
            color: #ff4d00;
            font-weight: 700;
            font-size: 16px;
        }

        /* Footer */
        .page-footer {
            text-align: center;
            padding: 30px 50px;
            font-size: 11px;
            color: #9ca3af;
            border-top: 1px solid #e5e7eb;
            page-break-before: avoid;
        }
    </style>
</head>
<body>
    {{-- Cover Page --}}
    <div class="cover">
        <div class="cover-logo">SPECTRA MEDIA</div>

        @if(!empty($data['hero_image']))
            <img src="{{ $data['hero_image'] }}" alt="Proposal Hero" class="cover-hero" />
        @endif

        <h1>Advertising Proposal</h1>
        <div class="subtitle">{{ $proposal->client_name }}</div>
        <div class="meta">
            Prepared {{ now()->format('F j, Y') }} &middot; {{ $proposal->industry ?? 'Digital Advertising' }}
        </div>
    </div>

    {{-- Executive Summary --}}
    <div class="page">
        <div class="section">
            <div class="section-title">Executive Summary</div>
            <div class="section-text">{{ $data['executive_summary'] ?? '' }}</div>
        </div>

        @if(!empty($data['industry_analysis']))
        <div class="section">
            <div class="section-title">Industry Analysis</div>
            <div class="section-text">{{ $data['industry_analysis'] }}</div>
        </div>
        @endif
    </div>

    {{-- Platform Strategies --}}
    @if(!empty($data['platform_strategies']))
    <div class="page">
        <div class="section-title" style="margin-bottom: 24px;">Platform Strategies</div>

        @foreach($data['platform_strategies'] as $strategy)
        <div class="platform-card">
            <div class="platform-header">
                {{ $strategy['platform'] ?? 'Platform' }}
                @if(!empty($strategy['budget_allocation']))
                    <span style="float: right; opacity: 0.9;">${{ number_format($strategy['budget_allocation'], 0) }}/mo</span>
                @endif
            </div>
            <div class="platform-body">
                @if(!empty($strategy['campaign_types']))
                <div class="tag-list">
                    @foreach($strategy['campaign_types'] as $type)
                        <span class="tag">{{ $type }}</span>
                    @endforeach
                </div>
                @endif

                <p>{{ $strategy['strategy_overview'] ?? '' }}</p>

                @if(!empty($strategy['targeting_approach']))
                <h4>Targeting Approach</h4>
                <p>{{ $strategy['targeting_approach'] }}</p>
                @endif

                @if(!empty($strategy['expected_metrics']))
                <h4>Expected Performance Metrics</h4>
                <div class="metrics-grid">
                    @foreach($strategy['expected_metrics'] as $label => $val)
                    <div class="metric-box">
                        <div class="metric-label">{{ str_replace('_', ' ', str_replace('estimated_', '', $label)) }}</div>
                        <div class="metric-value">{{ $val }}</div>
                    </div>
                    @endforeach
                </div>
                @endif

                @if(!empty($strategy['sample_ad_concepts']))
                <h4>Sample Ad Concepts</h4>
                @foreach($strategy['sample_ad_concepts'] as $ad)
                <div class="ad-concept">
                    <div class="headline">{{ $ad['headline'] ?? '' }}</div>
                    <div class="desc">{{ $ad['description'] ?? '' }}</div>
                    @if(!empty($ad['call_to_action']))
                    <div class="cta">{{ $ad['call_to_action'] }}</div>
                    @endif
                </div>
                @endforeach
                @endif
            </div>
        </div>
        @endforeach
    </div>
    @endif

    {{-- Timeline --}}
    @if(!empty($data['timeline']))
    <div class="page">
        <div class="section">
            <div class="section-title">Implementation Timeline</div>
            @foreach($data['timeline'] as $phase)
            <div class="timeline-item">
                <div class="timeline-marker">
                    <div class="timeline-dot"></div>
                    <div class="timeline-line"></div>
                </div>
                <div class="timeline-content">
                    <h4>{{ $phase['phase'] ?? '' }}</h4>
                    <div class="duration">{{ $phase['duration'] ?? '' }}</div>
                    @if(!empty($phase['activities']))
                    <ul>
                        @foreach($phase['activities'] as $activity)
                        <li>{{ $activity }}</li>
                        @endforeach
                    </ul>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Projected Results --}}
    @if(!empty($data['projected_results']))
    <div class="page">
        <div class="section">
            <div class="section-title">Projected Results</div>
            <div class="results-grid">
                @foreach($data['projected_results'] as $period => $result)
                <div class="result-card">
                    <h4>{{ ucfirst(str_replace('_', ' ', $period)) }}</h4>
                    <p>{{ $result }}</p>
                </div>
                @endforeach
            </div>
        </div>

        {{-- Investment Summary --}}
        @if(!empty($data['investment_summary']))
        <div class="section" style="margin-top: 36px;">
            <div class="section-title">Investment Summary</div>
            <table class="investment-table">
                <tbody>
                    @foreach($data['investment_summary'] as $item => $value)
                    <tr class="{{ $loop->last ? 'total' : '' }}">
                        <td>{{ ucfirst(str_replace('_', ' ', $item)) }}</td>
                        <td class="value">
                            @if(is_numeric($value))
                                ${{ number_format((float)$value, 2) }}
                            @else
                                {{ $value }}
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
    @endif

    {{-- Why Us --}}
    @if(!empty($data['why_us']))
    <div class="page">
        <div class="section">
            <div class="section-title">Why Spectra Media?</div>
            <ul class="why-us-list">
                @foreach($data['why_us'] as $point)
                <li>{{ $point }}</li>
                @endforeach
            </ul>
        </div>
    </div>
    @endif

    <div class="page-footer">
        <p>This proposal was generated by Spectra Media's AI-powered platform &middot; {{ $proposal->client_name }} &middot; {{ now()->format('F Y') }}</p>
        <p style="margin-top: 4px;">Confidential &mdash; For the intended recipient only.</p>
    </div>
</body>
</html>
