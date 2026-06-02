<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Unsubscribed</title>
    <style>body { font-family: sans-serif; max-width: 480px; margin: 80px auto; text-align: center; color: #2d3748; }</style>
</head>
<body>
    <h1>You've been unsubscribed</h1>
    <p>Hi {{ $user->name }}, you will no longer receive performance report emails.</p>
    <p style="margin-top: 32px;"><a href="{{ url('/dashboard') }}" style="color: #ff4d00;">Back to dashboard</a></p>
</body>
</html>
