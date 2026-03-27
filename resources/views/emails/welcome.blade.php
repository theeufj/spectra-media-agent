@extends('layouts.email')

@section('title', 'Welcome to Site to Spend')

@section('content')
    <h1>Welcome Aboard!</h1>
    <p>Hi {{ $name }},</p>
    <p>We're thrilled to have you join the Site to Spend community. You're just a few steps away from launching your first AI-powered ad campaign and transforming your digital marketing.</p>
    <p>Here's what you can do to get started:</p>
    <ul>
        <li><strong>Create a Campaign:</strong> Head to your dashboard to start your first campaign.</li>
        <li><strong>Set Your Goals:</strong> Define your objectives and let our AI handle the strategy.</li>
        <li><strong>Launch & Optimize:</strong> Deploy your ads and watch the results come in.</li>
    </ul>
    <p style="text-align: center; margin-top: 30px; margin-bottom: 30px;">
        <a href="{{ url('/dashboard') }}" class="btn-primary" style="background: linear-gradient(135deg, #ff4d00 0%, #cc3d00 100%); color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: 700;">Go to Your Dashboard</a>
    </p>
    <p>If you have any questions, don't hesitate to reach out to our support team.</p>
@endsection
