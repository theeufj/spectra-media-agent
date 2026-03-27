@extends('layouts.email')

@section('title', 'Your Invoice from Site to Spend')

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
        <a href="{{ route('subscription.portal') }}" style="background: linear-gradient(135deg, #ff4d00 0%, #cc3d00 100%); color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: 700;">Go to Billing Portal</a>
    </p>
@endsection
