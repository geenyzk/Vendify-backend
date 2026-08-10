<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading ?? 'Vendify' }}</title>
</head>
<body style="margin:0;padding:0;background:#f7f7fa;color:#111827;font-family:Arial,'Helvetica Neue',Helvetica,sans-serif;">
    <div style="display:none;max-height:0;overflow:hidden;color:#f7f7fa;opacity:0;">
        {{ $preheader ?? 'Vendify notification' }}
    </div>
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f7f7fa;margin:0;padding:0;">
        <tr>
            <td align="center" style="padding:28px 16px;">
                <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
                    <tr>
                        <td style="padding:24px 28px;border-top:2px solid #ff7a1a;border-bottom:1px solid #eef2f7;">
                            <div style="font-size:20px;font-weight:700;line-height:1.2;color:#111827;">Vendify <span style="display:inline-block;width:6px;height:6px;margin-left:4px;border-radius:999px;background:#0bbf74;vertical-align:middle;"></span></div>
                            <div style="margin-top:4px;font-size:12px;line-height:1.5;color:#64748b;">Fast, secure VTU payments</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:700;color:#111827;">{{ $heading ?? 'Vendify notification' }}</h1>
                            @isset($intro)
                                <p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#334155;">{{ $intro }}</p>
                            @endisset
                            @isset($body)
                                <p style="margin:0 0 20px;font-size:14px;line-height:1.7;color:#475569;white-space:pre-line;">{!! \App\Support\MailDeliverability::styleLinks((string) $body) !!}</p>
                            @endisset
                            @isset($details)
                                <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:20px 0;border:1px solid #e5e7eb;border-radius:6px;">
                                    @foreach($details as $label => $value)
                                        <tr>
                                            <td style="padding:10px 12px;border-bottom:1px solid #eef2f7;font-size:12px;color:#64748b;">{{ $label }}</td>
                                            <td align="right" style="padding:10px 12px;border-bottom:1px solid #eef2f7;font-size:13px;color:#111827;font-weight:600;">{{ $value }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endisset
                            @if(!empty($actionText) && !empty($actionUrl))
                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;">
                                    <tr>
                                        <td style="border-radius:8px;background:#111827;box-shadow:0 0 0 1px rgba(255,122,26,0.18);">
                                            <a href="{{ $actionUrl }}" style="display:inline-block;padding:12px 18px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;">{{ $actionText }}</a>
                                        </td>
                                    </tr>
                                </table>
                                <p style="margin:0 0 18px;font-size:12px;line-height:1.6;color:#64748b;">If the button does not work, copy and paste this link into your browser:<br><a href="{{ $actionUrl }}" style="color:#111827;word-break:break-all;">{{ $actionUrl }}</a></p>
                            @endif
                            <p style="margin:22px 0 0;font-size:13px;line-height:1.6;color:#64748b;">Need help? Contact <a href="mailto:support@vendify.com.ng" style="color:#111827;">support@vendify.com.ng</a>.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 28px;background:#f8fafc;border-top:1px solid #eef2f7;">
                            <p style="margin:0;font-size:12px;line-height:1.6;color:#94a3b8;">{{ $footerNote ?? 'This email was sent by Vendify.' }}</p>
                            <p style="margin:8px 0 0;font-size:12px;line-height:1.6;color:#94a3b8;">&copy; {{ date('Y') }} Vendify. All rights reserved.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
