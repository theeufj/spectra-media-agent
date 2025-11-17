@extends('layouts.email')

@section('title', $subject)

@section('content')
    <h1>{{ $subject }}</h1>
    <p>Hi {{ $user->name }},</p>
    <p>Here is a new notification from the CVSEEYOU team:</p>
    <div style="background-color: #edf2f7; padding: 20px; border-radius: 8px; margin-top: 20px; margin-bottom: 20px;">
        {!! $body !!}
    </div>
    <p>If you have any questions, please don't hesitate to contact our support team.</p>
    <p>Best,<br>The CVSEEYOU Team</p>
@endsection
