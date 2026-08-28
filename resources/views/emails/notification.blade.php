@extends('layouts.email')

{{-- Generic body for notification MailMessages (TenantAware::brandedMail).
     Consumes the standard MailMessage variables — greeting, introLines,
     actionText/actionUrl, outroLines, salutation — inside the same branded
     layout every hand-built mailable uses, so a scan-finished notification
     dresses the same as a welcome email. --}}

@section('title', $subject ?? ($tenantName ?? 'Site to Spend'))

@section('content')
    @if (! empty($greeting))
        <h1>{{ $greeting }}</h1>
    @endif

    @foreach ($introLines ?? [] as $line)
        <p>{{ $line }}</p>
    @endforeach

    @if (! empty($actionText))
        <p style="text-align: center; margin: 32px 0;">
            <a href="{{ $actionUrl }}"
               style="display: inline-block; background: linear-gradient(135deg, {{ $tenantPrimary ?? '#ff4d00' }} 0%, {{ $tenantDark ?? '#cc3d00' }} 100%); color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: 700; font-size: 15px;">
                {{ $actionText }}
            </a>
        </p>
    @endif

    @foreach ($outroLines ?? [] as $line)
        <p>{{ $line }}</p>
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
