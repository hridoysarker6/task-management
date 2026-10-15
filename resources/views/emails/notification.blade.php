<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">
                <tr>
                    <td style="padding:20px 28px;border-bottom:3px solid #4f46e5;">
                        @if ($logo)
                            <img src="{{ $logo }}" alt="{{ $company }}" height="36" style="display:block;max-height:36px;">
                        @else
                            <span style="font-size:18px;font-weight:700;color:#4f46e5;">{{ $company }}</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px;">
                        <h1 style="margin:0 0 12px;font-size:18px;line-height:1.4;">{{ $title }}</h1>
                        @if ($body)
                            <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#374151;white-space:pre-line;">{{ $body }}</p>
                        @endif
                        <a href="{{ $url }}" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:10px 18px;border-radius:8px;">{{ $actionText }}</a>
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 28px;background:#f9fafb;font-size:12px;color:#6b7280;">
                        Sent by {{ $company }} on {{ config('app.name') }}.
                        @if ($preferencesUrl)
                            <a href="{{ $preferencesUrl }}" style="color:#4f46e5;">Choose which emails you get</a>.
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
