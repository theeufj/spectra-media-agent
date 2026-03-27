@extends('layouts.email')

@section('title', 'New Support Ticket')

@section('content')
    <h1>New Support Ticket #{{ $ticket->id }}</h1>
    <p>A new support ticket has been submitted.</p>

    <div style="background-color: #edf2f7; padding: 20px; border-radius: 8px; margin-top: 20px; margin-bottom: 20px;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="padding: 8px 0; font-weight: bold; color: #4a5568; width: 120px;">From:</td>
                <td style="padding: 8px 0;">{{ $ticket->user->name }} ({{ $ticket->user->email }})</td>
            </tr>
            @if($ticket->customer)
            <tr>
                <td style="padding: 8px 0; font-weight: bold; color: #4a5568;">Customer:</td>
                <td style="padding: 8px 0;">{{ $ticket->customer->name }}</td>
            </tr>
            @endif
            <tr>
                <td style="padding: 8px 0; font-weight: bold; color: #4a5568;">Subject:</td>
                <td style="padding: 8px 0;">{{ $ticket->subject }}</td>
            </tr>
            <tr>
                <td style="padding: 8px 0; font-weight: bold; color: #4a5568;">Priority:</td>
                <td style="padding: 8px 0;">
                    <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; text-transform: uppercase;
                        @if($ticket->priority === 'urgent') background-color: #fed7d7; color: #9b2c2c;
                        @elseif($ticket->priority === 'high') background-color: #feebc8; color: #9c4221;
                        @else background-color: #c6f6d5; color: #276749;
                        @endif
                    ">{{ $ticket->priority }}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 8px 0; font-weight: bold; color: #4a5568;">Category:</td>
                <td style="padding: 8px 0;">{{ ucfirst($ticket->category ?? 'General') }}</td>
            </tr>
        </table>
    </div>

    <h2 style="font-size: 16px; color: #2d3748; margin-top: 24px;">Description</h2>
    <div style="background-color: #ffffff; border: 1px solid #e2e8f0; padding: 16px; border-radius: 8px; margin-top: 8px;">
        {!! nl2br(e($ticket->description)) !!}
    </div>

    <p style="text-align: center; margin-top: 30px; margin-bottom: 30px;">
        <a href="{{ url('/admin/support-tickets/' . $ticket->id) }}" style="background: linear-gradient(135deg, #ff4d00 0%, #cc3d00 100%); color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: 700;">View Ticket in Admin</a>
    </p>
@endsection
