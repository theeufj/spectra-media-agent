<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title')</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol';
            line-height: 1.6;
            color: #3d4852;
            background-color: #f8fafc;
            margin: 0;
            padding: 20px;
        }
        .wrapper {
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border: 1px solid #e8e5ef;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        .header {
            padding: 20px 30px;
            background-color: #4a5568;
            color: #ffffff;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .content {
            padding: 30px;
        }
        .content h1 {
            color: #2d3748;
            font-size: 22px;
        }
        .content p {
            margin-bottom: 1.2em;
        }
        .footer {
            padding: 20px 30px;
            text-align: center;
            font-size: 12px;
            color: #a0aec0;
            background-color: #f7fafc;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <h1>CVSEEYOU</h1>
        </div>
        <div class="content">
            @yield('content')
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} CVSEEYOU. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
