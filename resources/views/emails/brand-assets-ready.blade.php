@extends('layouts.email')

@section('title', 'Your brand assets are ready')

@section('content')
    <h1>Your brand assets are ready</h1>

    <p>Hi {{ $user->name }},</p>

    <p>We've analyzed {{ $pagesExtracted }} {{ \Illuminate\Support\Str::plural('page', $pagesExtracted) }} from <strong>{{ $customer->name }}</strong> and captured your brand's visual identity.</p>

    <div style="background:#f7fafc;border:1px solid #e8e5ef;border-radius:6px;padding:16px;margin:16px 0;">
        <p style="margin:0 0 8px;"><strong>Primary colors</strong> &mdash; your brand palette, captured as hex codes</p>
        <p style="margin:0 0 8px;"><strong>Typography</strong> &mdash; fonts identified and ready to use</p>
        <p style="margin:0 0 8px;"><strong>Brand voice</strong> &mdash; tone and messaging patterns</p>
        <p style="margin:0;"><strong>Visual style</strong> &mdash; key design elements</p>
    </div>

    <p>Your dashboard is now populated with on-brand ad campaigns ready for you to review.</p>

    <p style="text-align:center;margin:28px 0;">
        <a href="{{ route('dashboard') }}" class="btn-primary">View your brand assets</a>
    </p>

    <p><strong>What's next?</strong></p>
    <ol>
        <li>Review your brand profile to make sure everything looks right</li>
        <li>Generate more ad variations whenever you need them</li>
        <li>Run a CRO audit to see how your landing pages convert</li>
    </ol>

    <p>Questions? Just reply to this email &mdash; we're happy to help.</p>

    <p>Thanks,<br>The {{ config('app.name') }} Team</p>
@endsection
