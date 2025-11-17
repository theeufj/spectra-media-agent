@extends('layouts.email')

@section('title', 'Your Invoice from CVSEEYOU')

@section('content')
    <h1>Invoice Details</h1>
    <p>Hi {{ $user->name }},</p>
    <p>Thank you for your payment. Here is a summary of your recent invoice:</p>
    <ul>
        <li><strong>Amount:</strong> ${{ $amount }}</li>
        <li><strong>Date:</strong> {{ $date }}</li>
    </ul>
    <p>You can view and manage all your invoices and billing details in your customer portal.</p>
    <p style="text-align: center; margin-top: 30px; margin-bottom: 30px;">
        <a href="{{ route('subscription.portal') }}" style="background-color: #4a5568; color: #ffffff; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold;">Go to Billing Portal</a>
    </p>
    <p>Best,<br>The CVSEEYOU Team</p>
@endsection
