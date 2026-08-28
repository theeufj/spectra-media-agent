@extends('layouts.email')

{{-- Generic body for notification MailMessages (TenantAware::brandedMail).
     Consumes the standard MailMessage variables — greeting, introLines,
     actionText/actionUrl, outroLines, salutation, level — inside the same
     branded layout every hand-built mailable uses, so a scan-finished
     notification dresses the same as a welcome email.

     Lines go through the same markdown pass as Laravel's stock notification
     template: **bold** et al. were written for CommonMark and must not
     surface as literal asterisks. Escaped first, so line content can't
     inject HTML. --}}

@php
    $mdLine = fn ($line) => $line instanceof \Illuminate\Contracts\Support\Htmlable
        ? $line->toHtml()
        : \Illuminate\Mail\Markdown::parse(e($line))->toHtml();

    // ->error() colors the button like the stock template did — a failure
    // email must not dress like a success email.
    $buttonStyle = ($level ?? null) === 'error'
        ? 'background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);'
        : 'background: linear-gradient(135deg, '.($tenantPrimary ?? '#ff4d00').' 0%, '.($tenantDark ?? '#cc3d00').' 100%);';
@endphp

@section('title', $subject ?? ($tenantName ?? 'Site to Spend'))

@section('content')
    @if (! empty($greeting))
        <h1>{{ $greeting }}</h1>
    @endif

    @foreach ($introLines ?? [] as $line)
        {!! $mdLine($line) !!}
    @endforeach

    @if (! empty($actionText))
        <p style="text-align: center; margin: 32px 0;">
            <a href="{{ $actionUrl }}"
               style="display: inline-block; {{ $buttonStyle }} color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: 700; font-size: 15px;">
                {{ $actionText }}
            </a>
        </p>
    @endif

    @foreach ($outroLines ?? [] as $line)
        {!! $mdLine($line) !!}
    @endforeach

    @if (! empty($salutation))
        <p style="color: #718096;">{{ $salutation }}</p>
    @endif

    @if (! empty($actionText))
        <p style="font-size: 12px; color: #a0aec0; border-top: 1px solid #e8e5ef; padding-top: 16px; margin-top: 32px;">
            If the "{{ $actionText }}" button doesn't work, copy and paste this URL into your browser:<br>
            <a href="{{ $actionUrl }}" style="color: {{ $tenantPrimary ?? '#ff4d00' }}; word-break: break-all;">{{ $displayableActionUrl ?? $actionUrl }}</a>
        </p>
    @endif
@endsection
