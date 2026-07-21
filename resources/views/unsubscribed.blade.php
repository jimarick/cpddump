<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unsubscribed — CPD Dump</title>
    <style>
        body { background: #FAF9F6; color: #1C1917; font-family: system-ui, sans-serif;
               display: grid; place-items: center; min-height: 100vh; margin: 0; }
        .card { background: #fff; border: 2px solid #1C1917; border-radius: 14px;
                box-shadow: 5px 5px 0 rgba(28,25,23,.12); padding: 36px 40px;
                max-width: 420px; text-align: center; rotate: -0.5deg; }
        h1 { font-size: 24px; margin: 0 0 8px; }
        p { color: #78716C; font-size: 14px; line-height: 1.5; margin: 0; }
        a { color: #F4590C; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Done — no more {{ match ($type) {
            'reminders' => 'reminders',
            'monthly' => 'monthly digests',
            default => 'weekly emails',
        } }}.</h1>
        <p>
            Your evidence keeps collecting either way.
            You can switch this back on any time in
            <a href="{{ route('notifications.edit') }}">your settings</a>.
        </p>
    </div>
</body>
</html>
