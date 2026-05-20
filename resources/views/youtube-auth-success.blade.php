<!DOCTYPE html>
<html>
<head>
    <title>YouTube Auth — Success</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 700px; margin: 80px auto; padding: 0 20px; background: #f9f9f9; }
        .card { background: white; border-radius: 12px; padding: 40px; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
        h1 { color: #16a34a; margin-top: 0; }
        .token { background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; font-family: monospace; font-size: 13px; word-break: break-all; }
        .note { color: #64748b; font-size: 14px; margin-top: 24px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>✓ YouTube refresh token saved</h1>
        <p>The refresh token has been written to <code>.env</code> automatically as <code>GOOGLE_YOUTUBE_REFRESH_TOKEN</code>.</p>

        <p><strong>Token (for reference):</strong></p>
        <div class="token">{{ $refresh_token }}</div>

        <div class="note">
            <p>Run <code>php artisan config:clear</code> on the server so Laravel picks up the new value, then repair Campaign 22's asset group:</p>
            <div class="token">php artisan pmax:repair-assets --strategy=730</div>
        </div>
    </div>
</body>
</html>
