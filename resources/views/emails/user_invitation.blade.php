@extends('layouts.email')

@section('title', 'You have been invited!')

@section('content')
    <h1>You've Been Invited!</h1>
    <p>You have been invited to join the <strong>{{ $invitation->customer->name }}</strong> customer account on CVSEEYOU.</p>
    <p>Your role will be: <strong>{{ $invitation->role }}</strong>.</p>
    <p>To accept this invitation, please click the button below:</p>
    <p style="text-align: center; margin-top: 30px; margin-bottom: 30px;">
        <a href="{{ route('invitations.accept', $invitation->token) }}" style="background-color: #4a5568; color: #ffffff; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold;">Accept Invitation</a>
    </p>
    <p>If you did not expect this invitation, you can ignore this email.</p>
@endsection
