@extends('layouts.email')

@section('title', 'Support Ticket Received')

@section('content')
    <h1>We received your request</h1>
    <p>Hi {{ $ticket->user->name }},</p>
    <p>Your support ticket has been submitted and our team will get back to you shortly.</p>

    <div style="background-color: #edf2f7; padding: 20px; border-radius: 8px; margin-top: 20px; margin-bottom: 20px;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="padding: 8px 0; font-weight: bold; color: #4a5568; width: 100px;">Ticket #:</td>
                <td style="padding: 8px 0;">#{{ $ticket->id }}</td>
            </tr>
            <tr>
                <td style="padding: 8px 0; font-weight: bold; color: #4a5568;">Subject:</td>
                <td style="padding: 8px 0;">{{ $ticket->subject }}</td>
            </tr>
            <tr>
                <td style="padding: 8px 0; font-weight: bold; color: #4a5568;">Priority:</td>
                <td style="padding: 8px 0;">{{ ucfirst($ticket->priority) }}</td>
            </tr>
            <tr>
                <td style="padding: 8px 0; font-weight: bold; color: #4a5568;">Category:</td>
                <td style="padding: 8px 0;">{{ ucfirst($ticket->category ?? 'General') }}</td>
            </tr>
        </table>
    </div>

    <p>We typically respond within 1 business day. High and urgent priority tickets are reviewed first.</p>

    <p style="text-align: center; margin-top: 30px; margin-bottom: 30px;">
        <a href="{{ url('/support-tickets/' . $ticket->id) }}" style="background: linear-gradient(135deg, #ff4d00 0%, #cc3d00 100%); color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: 700;">View Your Ticket</a>
    </p>

    <p style="color: #718096; font-size: 14px;">If you need immediate assistance, reply to this email and reference ticket #{{ $ticket->id }}.</p>
@endsection
