{{--
    Branded notification email (email-client-safe: tables + inline styles only).
    Shared layout for every portal email — incident reminders, action
    improvements, investigation forms, reports, agent runs, password reset.

    Vars:
    - $headline        header-band title
    - $intro           lead paragraph (optional)
    - $details         assoc label => value rows (optional)
    - $lines           paragraph list; **bold** supported, HTML-escaped (optional)
    - $bodyHtml        pre-escaped HTML block (investigation-form/agent bodies, optional)
    - $actionText/$actionUrl   primary button (optional)
    - $secondaryText/$secondaryUrl  small text link under the button (optional)
    - $greeting        "Hello X," (optional)
    - $footer          footer note (optional)
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
                        <td style="background:#108EE9;padding:22px 28px;">
                            <span style="font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#b8e1fa;">TechRisk Portal</span><br>
                            <span style="font-size:18px;font-weight:700;color:#ffffff;">{{ $headline }}</span>
                        </td>
                    </tr>
                    {{-- Body --}}
                    <tr>
                        <td style="padding:26px 28px;">
                            @isset($greeting)
                                <p style="margin:0 0 4px;font-size:15px;color:#475569;">{{ $greeting }}</p>
                            @endisset

                            @isset($intro)
                                <p style="margin:0 0 18px;font-size:15px;line-height:1.6;color:#334155;">{{ $intro }}</p>
                            @endisset

                            @if (! empty($lines))
                                @foreach ($lines as $line)
                                    <p style="margin:0 0 10px;font-size:15px;line-height:1.6;color:#334155;">{!! Illuminate\Support\Str::inlineMarkdown($line, ['html_input' => 'escape']) !!}</p>
                                @endforeach
                            @endif

                            @if (! empty($details))
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;@empty($intro) margin-top:6px;@endempty">
                                    @foreach ($details as $label => $value)
                                        <tr>
                                            <td style="padding:8px 12px;border:1px solid #e2e8f0;background:#f8fafc;color:#64748b;font-weight:600;white-space:nowrap;width:160px;">{{ $label }}</td>
                                            <td style="padding:8px 12px;border:1px solid #e2e8f0;color:#1f2937;">{{ $value }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            @isset($bodyHtml)
                                <div style="margin:14px 0;font-size:14px;line-height:1.6;color:#334155;">{!! $bodyHtml !!}</div>
                            @endisset

                            @if (! empty($actionUrl))
                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:24px;">
                                    <tr>
                                        <td style="border-radius:8px;background:#108EE9;">
                                            <a href="{{ $actionUrl }}" style="display:inline-block;padding:12px 24px;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;border-radius:8px;">{{ $actionText }}</a>
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            @if (! empty($secondaryUrl) && ! empty($secondaryText))
                                <p style="margin:14px 0 0;font-size:13px;color:#64748b;">
                                    <a href="{{ $secondaryUrl }}" style="color:#108EE9;text-decoration:underline;font-weight:600;">{{ $secondaryText }}</a>
                                </p>
                            @endif
                        </td>
                    </tr>
                    {{-- Footer --}}
                    <tr>
                        <td style="padding:16px 28px;border-top:1px solid #e2e8f0;background:#f8fafc;">
                            <p style="margin:0;font-size:12px;color:#94a3b8;line-height:1.5;">{{ $footer ?? 'This is an automated message from the Technical Risk Portal. Please do not reply to this email.' }}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
