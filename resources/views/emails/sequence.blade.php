@extends('layouts.email')

@section('title', 'Site to Spend')

@section('content')
    {{-- Deliberately plain. A founder's follow-up that arrives looking like a
         marketing template contradicts the thing it is trying to say. --}}
    <div style="font-size: 15px; line-height: 1.6; color: #2d3748;">
        {!! $bodyHtml !!}
    </div>

    <div style="margin-top: 28px; font-size: 15px; line-height: 1.6; color: #2d3748;">
        {!! $signature !!}
    </div>

    <div style="margin-top: 36px; padding-top: 16px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #a0aec0;">
        <a href="{{ $unsubscribeUrl }}" style="color: #a0aec0;">Unsubscribe</a> — you'll hear nothing further from us.
    </div>
@endsection
