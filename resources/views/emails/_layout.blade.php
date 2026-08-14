<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('subject')</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f4f4f5; color: #18181b; font-size: 15px; line-height: 1.6; }
    .wrapper { max-width: 600px; margin: 40px auto; background: #ffffff; border-radius: 8px; overflow: hidden; border: 1px solid #e4e4e7; }
    .header { padding: 28px 32px 24px; border-bottom: 1px solid #e4e4e7; }
    .header .brand { font-size: 18px; font-weight: 600; letter-spacing: -0.3px; color: #18181b; }
    .body { padding: 32px; }
    .body h1 { font-size: 20px; font-weight: 600; margin-bottom: 12px; }
    .body p { color: #52525b; margin-bottom: 14px; }
    .body p:last-child { margin-bottom: 0; }
    .meta { background: #fafafa; border: 1px solid #e4e4e7; border-radius: 6px; padding: 16px 20px; margin: 20px 0; }
    .meta dt { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #a1a1aa; margin-bottom: 2px; }
    .meta dd { font-size: 14px; font-weight: 500; margin-bottom: 12px; }
    .meta dd:last-child { margin-bottom: 0; }
    .btn { display: inline-block; margin-top: 20px; background: #18181b; color: #ffffff !important; text-decoration: none; padding: 11px 22px; border-radius: 6px; font-size: 14px; font-weight: 500; }
    .note { font-size: 13px; color: #a1a1aa; margin-top: 20px; border-top: 1px solid #e4e4e7; padding-top: 16px; }
    .footer { padding: 20px 32px; border-top: 1px solid #e4e4e7; font-size: 12px; color: #a1a1aa; }
  </style>
</head>
<body>
<div class="wrapper">
  <div class="header"><span class="brand">Freelancer Protect</span></div>
  <div class="body">
    @yield('content')
  </div>
  <div class="footer">
    You are receiving this because you are a party to this contract.
    Freelancer Protect · {{ config('app.url') }}
  </div>
</div>
</body>
</html>
