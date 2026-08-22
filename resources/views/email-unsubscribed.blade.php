<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Unsubscribed</title>
    <style>body { font-family: sans-serif; max-width: 480px; margin: 80px auto; text-align: center; color: #2d3748; }</style>
</head>
<body>
    <h1>You've been unsubscribed</h1>

    {{-- A landing-page lead has no account, so $user may be null. Addressing
         someone by name was safe only while registered users were the only
         people who could reach this page. --}}
    @isset($user)
        <p>Hi {{ $user->name }}, you won't hear from us again.</p>
        <p style="margin-top: 32px;"><a href="{{ url('/dashboard') }}" style="color: #ff4d00;">Back to dashboard</a></p>
    @else
        <p>You won't hear from us again.</p>
    @endisset
</body>
</html>
