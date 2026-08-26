@extends('layouts.email')

@section('title', 'Your landing page audit is ready')

@section('content')
    <h1>Your landing page audit is ready</h1>

    <p>Hi {{ $user->name }},</p>

    <p>We've finished auditing <strong>{{ $audit->url }}</strong> and found {{ $issuesFound }} {{ \Illuminate\Support\Str::plural('issue', $issuesFound) }} worth reviewing.</p>

    <div style="background:#f7fafc;border:1px solid #e8e5ef;border-radius:6px;padding:16px;margin:16px 0;">
        <p style="margin:0 0 8px;"><strong>Conversion score: {{ $audit->overall_score }}/100</strong></p>
        @if($audit->overall_score < 50)
        <p style="margin:0;">There's substantial room to improve how this page converts visitors.</p>
        @elseif($audit->overall_score < 70)
        <p style="margin:0;">A solid foundation with meaningful opportunities to improve.</p>
        @else
        <p style="margin:0;">A strong page &mdash; a few refinements could push it higher.</p>
        @endif
    </div>

    @if($audit->issues && count($audit->issues) > 0)
    <p><strong>Top issues we found:</strong></p>
    <ul>
        @foreach(array_slice($audit->issues, 0, 3) as $issue)
        <li>{{ is_array($issue) ? ($issue['title'] ?? 'Issue') : $issue }}</li>
        @endforeach
    </ul>
    @endif

    <p>The full report includes step-by-step fixes and A/B testing recommendations for each issue.</p>

    <p style="text-align:center;margin:28px 0;">
        <a href="{{ ($tenantBaseUrl ?? url('')) }}{{ route('subscription.pricing', absolute: false) }}" class="btn-primary">See the full report</a>
    </p>

    @php($auditsLeft = max(0, 3 - $customer->landingPageAudits->count()))
    @if($auditsLeft > 0)
    <p>You have {{ $auditsLeft }} free {{ \Illuminate\Support\Str::plural('audit', $auditsLeft) }} remaining on your account.</p>
    @endif

    <p>Questions? Just reply to this email.</p>

    <p>Thanks,<br>The {{ config('app.name') }} Team</p>
@endsection
