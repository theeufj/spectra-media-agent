<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collateral Generated</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .email-container {
            max-width: 600px;
            margin: 40px auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .content {
            padding: 40px 30px;
        }
        .greeting {
            font-size: 18px;
            margin-bottom: 20px;
            color: #2d3748;
        }
        .message {
            margin-bottom: 30px;
            color: #4a5568;
            font-size: 16px;
        }
        .campaign-details {
            background-color: #f7fafc;
            border-left: 4px solid #667eea;
            padding: 20px;
            margin: 30px 0;
            border-radius: 4px;
        }
        .campaign-name {
            font-size: 20px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 10px;
        }
        .stats {
            display: flex;
            justify-content: space-around;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
        }
        .stat-item {
            text-align: center;
        }
        .stat-number {
            font-size: 24px;
            font-weight: 700;
            color: #667eea;
        }
        .stat-label {
            font-size: 12px;
            color: #718096;
            text-transform: uppercase;
            margin-top: 5px;
        }
        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            padding: 14px 32px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 16px;
            margin: 20px 0;
            transition: transform 0.2s;
        }
        .cta-button:hover {
            transform: translateY(-2px);
        }
        .footer {
            background-color: #f7fafc;
            padding: 30px;
            text-align: center;
            color: #718096;
            font-size: 14px;
        }
        .footer-links {
            margin-top: 15px;
        }
        .footer-links a {
            color: #667eea;
            text-decoration: none;
            margin: 0 10px;
        }
        .highlight {
            color: #667eea;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>🎉 Your Collateral is Ready!</h1>
        </div>

        <div class="content">
            <div class="greeting">
                Hi {{ $user->name }},
            </div>

            <div class="message">
                Great news! We've finished generating all the creative collateral for your campaign. Your images and videos are now ready for review and deployment.
            </div>

            <div class="campaign-details">
                <div class="campaign-name">{{ $campaign->name }}</div>
                <p style="color: #718096; margin: 10px 0 0 0; font-size: 14px;">
                    All strategies have been signed off and processed.
                </p>
                <div class="stats">
                    <div class="stat-item">
                        <div class="stat-number">{{ $campaign->strategies->count() }}</div>
                        <div class="stat-label">Strategies</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">{{ $campaign->strategies->count() * 3 }}+</div>
                        <div class="stat-label">Images</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">{{ $campaign->strategies->count() * 2 }}+</div>
                        <div class="stat-label">Videos</div>
                    </div>
                </div>
            </div>

            <div class="message">
                <strong>What's next?</strong>
                <ul style="margin-top: 15px; padding-left: 20px;">
                    <li style="margin-bottom: 10px;">Review all generated images and videos</li>
                    <li style="margin-bottom: 10px;">Select your favorites for deployment</li>
                    <li style="margin-bottom: 10px;">Request refinements if needed</li>
                    <li>Deploy to your advertising platforms with one click</li>
                </ul>
            </div>

            <div style="text-align: center;">
                <a href="{{ route('campaigns.collateral.show', ['campaign' => $campaign->id, 'strategy' => $campaign->strategies->first()->id]) }}" class="cta-button">
                    View Your Collateral
                </a>
            </div>

            <div class="message" style="margin-top: 30px; font-size: 14px; color: #718096;">
                If you have any questions or need assistance, our team is here to help.
            </div>
        </div>

        <div class="footer">
            <p>This is an automated notification from your campaign management system.</p>
            <div class="footer-links">
                <a href="{{ route('campaigns.index') }}">View All Campaigns</a> |
                <a href="{{ route('dashboard') }}">Dashboard</a>
            </div>
            <p style="margin-top: 20px; font-size: 12px;">
                © {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
            </p>
        </div>
    </div>
</body>
</html>
