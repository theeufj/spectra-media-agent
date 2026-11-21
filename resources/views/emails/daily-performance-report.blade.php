@extends('layouts.email')

@section('title', 'Daily Performance Report')

@section('content')
    @php
        $money = static function (array $metrics, string $field = 'spend') {
            if ($field === 'spend') {
                $amounts = [];
                foreach ($metrics['money_by_currency'] ?? [] as $currency => $values) {
                    $amounts[] = $currency.' '.number_format((float) $values['spend'], 2);
                }
                if ($metrics['currency_unverified'] ?? false) $amounts[] = 'Currency unverified';
                return $amounts ? implode(' + ', $amounts) : '—';
            }
            return isset($metrics[$field], $metrics['currency'])
                ? $metrics['currency'].' '.number_format($metrics[$field], 2) : '—';
        };
        $count = static fn (array $metrics) => isset($metrics['conversions']) ? number_format($metrics['conversions'], 1)
            : (($metrics['confirmed_conversions'] ?? 0) > 0 ? number_format($metrics['confirmed_conversions'], 1).' confirmed' : 'Unverified');
    @endphp
    <h1 style="margin-top: 0;">Daily Performance Report</h1>
    <p>Hi {{ $user->name }},</p>
    <p>Here's your campaign performance summary for <strong>{{ $summary['date'] }}</strong>.</p>

    @if(isset($summary['first_party']))
        <p>Your website recorded {{ $summary['first_party']['signups'] }} signups, {{ $summary['first_party']['leads'] ?? 0 }} leads and {{ $summary['first_party']['purchases'] }} paid orders. Connected CRMs recorded {{ $summary['first_party']['crm_deals'] }} closed deals.</p>
        @foreach($summary['first_party']['orders_by_currency'] ?? [] as $currency => $order)
            <p>{{ $currency }} · {{ $order['orders'] }} orders · Gross {{ number_format($order['gross_cents'] / 100, 2) }} · {{ $order['refunds'] }} refunds totaling {{ number_format($order['refund_cents'] / 100, 2) }} · Net {{ number_format($order['net_cents'] / 100, 2) }}</p>
        @endforeach
    @endif

    {{-- Combined Totals --}}
    <table width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0; border-collapse: collapse;">
        <tr>
            <td width="50%" style="padding: 16px; background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 6px 0 0 0;">
                <div style="font-size: 12px; color: #718096; text-transform: uppercase; letter-spacing: 0.5px;">Spend</div>
                <div style="font-size: 24px; font-weight: 700; color: #2d3748;">{{ $money($summary['combined']) }}</div>
                @if(($summary['changes']['spend'] ?? 0) != 0)
                    <div style="font-size: 12px; color: {{ $summary['changes']['spend'] > 0 ? '#e53e3e' : '#38a169' }};">
                        {{ $summary['changes']['spend'] > 0 ? '+' : '' }}{{ $summary['changes']['spend'] }}% vs prior day
                    </div>
                @endif
            </td>
            <td width="50%" style="padding: 16px; background: #f7fafc; border: 1px solid #e2e8f0; border-left: none; border-radius: 0 6px 0 0;">
                <div style="font-size: 12px; color: #718096; text-transform: uppercase; letter-spacing: 0.5px;">Platform-reported conversions</div>
                <div style="font-size: 24px; font-weight: 700; color: #2d3748;">{{ $count($summary['combined']) }}</div>
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
                <div style="font-size: 18px; font-weight: 700; color: #2d3748;">{{ $money($summary['combined'], 'cpc') }}</div>
            </td>
            <td width="33%" style="padding: 12px; text-align: center; background: #fff5f5; border: 1px solid #fed7d7; border-left: none; border-radius: 0 6px 6px 0;">
                <div style="font-size: 11px; color: #718096; text-transform: uppercase;">CPA</div>
                <div style="font-size: 18px; font-weight: 700; color: #2d3748;">{{ $money($summary['combined'], 'cpa') }}</div>
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
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;">{{ $money($summary['google']) }}</td>
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;">{{ number_format($summary['google']['clicks']) }}</td>
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;">{{ number_format($summary['google']['impressions']) }}</td>
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;">{{ $count($summary['google']) }}</td>
                </tr>
                @endif
                @if($summary['facebook']['impressions'] > 0)
                <tr>
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0;">Facebook Ads</td>
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;">{{ $money($summary['facebook']) }}</td>
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;">{{ number_format($summary['facebook']['clicks']) }}</td>
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;">{{ number_format($summary['facebook']['impressions']) }}</td>
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;">{{ $count($summary['facebook']) }}</td>
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
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;">{{ $money($campaign['combined'] ?? []) }}</td>
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;">{{ number_format($campaign['combined']['clicks'] ?? (($campaign['google']['clicks'] ?? 0) + ($campaign['facebook']['clicks'] ?? 0))) }}</td>
                    <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;">{{ $count($campaign['combined'] ?? []) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- What we optimized on the client's behalf --}}
    @php $opt = $summary['optimizations'] ?? ['applied' => [], 'pending' => 0]; @endphp
    @if(!empty($opt['applied']))
        <div style="margin: 24px 0; padding: 16px 20px; background: #f0fdf4; border-left: 4px solid #10b981; border-radius: 4px;">
            <div style="font-size: 11px; color: #047857; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 10px;">What we optimized for you</div>
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
            <div style="font-size: 14px; color: #2d3748; font-weight: 600;">{{ $opt['pending'] }} optimization {{ \Illuminate\Support\Str::plural('recommendation', $opt['pending']) }} ready for your review</div>
            <div style="font-size: 13px; color: #718096; margin-top: 2px;">We've queued {{ $opt['pending'] > 1 ? 'these' : 'this' }} for your approval in the dashboard.</div>
        </div>
    @endif

    <p style="text-align: center; margin-top: 30px; margin-bottom: 30px;">
        <a href="{{ ($tenantBaseUrl ?? url('')) }}{{ route('dashboard', absolute: false) }}" style="background: linear-gradient(135deg, #ff4d00 0%, #cc3d00 100%); color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: 700;">View Dashboard</a>
    </p>

    <p style="text-align: center; font-size: 12px; color: #a0aec0; margin-top: 24px;">
        <a href="{{ URL::signedRoute('email.unsubscribe', ['user' => $user->id]) }}" style="color: #a0aec0;">Unsubscribe from performance reports</a>
    </p>
@endsection
