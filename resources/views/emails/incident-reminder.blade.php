{{--
    Branded reminder email (email-client-safe: tables + inline styles only).
    Vars: $greeting, $headline, $intro, $details (assoc label => value), $actionText, $actionUrl
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $headline }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">
                    {{-- Header band --}}
                    <tr>
                        <td style="background:#4f46e5;padding:22px 28px;">
                            <span style="font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#c7d2fe;">TechRisk Dashboard</span><br>
                            <span style="font-size:18px;font-weight:700;color:#ffffff;">{{ $headline }}</span>
                        </td>
                    </tr>
                    {{-- Body --}}
                    <tr>
                        <td style="padding:26px 28px;">
                            <p style="margin:0 0 4px;font-size:15px;color:#475569;">{{ $greeting }}</p>
                            <p style="margin:0 0 18px;font-size:15px;line-height:1.6;color:#334155;">{{ $intro }}</p>

                            @if (! empty($details))
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;">
                                    @foreach ($details as $label => $value)
                                        <tr>
                                            <td style="padding:8px 12px;border:1px solid #e2e8f0;background:#f8fafc;color:#64748b;font-weight:600;white-space:nowrap;width:160px;">{{ $label }}</td>
                                            <td style="padding:8px 12px;border:1px solid #e2e8f0;color:#1f2937;">{{ $value }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            @if (! empty($actionUrl))
                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:24px;">
                                    <tr>
                                        <td style="border-radius:8px;background:#4f46e5;">
                                            <a href="{{ $actionUrl }}" style="display:inline-block;padding:12px 24px;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;border-radius:8px;">{{ $actionText }}</a>
                                        </td>
                                    </tr>
                                </table>
                            @endif
                        </td>
                    </tr>
                    {{-- Footer --}}
                    <tr>
                        <td style="padding:16px 28px;border-top:1px solid #e2e8f0;background:#f8fafc;">
                            <p style="margin:0;font-size:12px;color:#94a3b8;line-height:1.5;">This is an automated reminder from the Technical Risk Dashboard. Please do not reply to this email.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
