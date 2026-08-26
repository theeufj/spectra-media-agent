@extends('layouts.email')

@section('title', $subject)

@section('content')
    <h1>{{ $subject }}</h1>
    <p>Hi {{ $user->name }},</p>
    <p>We're reaching out with an important update regarding your {{ $tenantName ?? 'Site to Spend' }} account:</p>
    <div style="background-color: #edf2f7; padding: 20px; border-radius: 8px; margin-top: 20px; margin-bottom: 20px;">
        {!! nl2br(e($body)) !!}
    </div>
    <p>If you have any questions about this notice, please reach out to our support team.</p>
@endsection
