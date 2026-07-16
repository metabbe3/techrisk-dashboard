<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.6;
            color: #1c1917;
            background: #fff;
        }

        .page { padding: 40px 50px; }

        /* Header */
        .header {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 24px 28px;
            background: #1c1917;
            border-radius: 10px;
            margin-bottom: 28px;
            color: #fff;
        }
        .header-icon {
            width: 48px;
            height: 48px;
            background: rgba(255,255,255,0.12);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .header h1 {
            font-size: 18pt;
            font-weight: 700;
            margin: 0 0 2px;
        }
        .header .subtitle {
            font-size: 10pt;
            opacity: 0.7;
        }

        /* Meta */
        .meta {
            display: flex;
            gap: 24px;
            padding: 12px 0;
            border-bottom: 1px solid #e8e5e0;
            margin-bottom: 24px;
            font-size: 9pt;
            color: #78716c;
        }
        .meta span { display: inline-flex; align-items: center; gap: 4px; }
        .meta strong { color: #1c1917; }

        /* Body — report content */
        .body {
            font-size: 11pt;
            line-height: 1.75;
        }

        .body h1 {
            font-size: 16pt;
            font-weight: 800;
            color: #1c1917;
            margin: 28px 0 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #d97706;
        }
        .body h1:first-child { margin-top: 0; }

        .body h2 {
            font-size: 14pt;
            font-weight: 700;
            color: #1c1917;
            margin: 24px 0 10px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e8e5e0;
        }
        .body h2:first-child { margin-top: 0; }

        .body h3 {
            font-size: 12pt;
            font-weight: 700;
            color: #1c1917;
            margin: 18px 0 8px;
        }

        .body h4 {
            font-size: 11pt;
            font-weight: 700;
            color: #78716c;
            margin: 14px 0 6px;
        }

        .body p {
            margin: 8px 0;
        }

        .body strong { font-weight: 700; color: #1c1917; }
        .body em { font-style: italic; color: #78716c; }

        .body ul {
            list-style: none;
            padding-left: 0;
            margin: 8px 0;
        }
        .body ul li {
            position: relative;
            padding-left: 16px;
            margin-bottom: 4px;
        }
        .body ul li::before {
            content: '\2022';
            position: absolute;
            left: 0;
            color: #d97706;
            font-weight: 700;
        }

        .body ol {
            list-style: none;
            counter-reset: ol-counter;
            padding-left: 0;
            margin: 8px 0;
        }
        .body ol li {
            position: relative;
            padding-left: 24px;
            margin-bottom: 4px;
            counter-increment: ol-counter;
        }
        .body ol li::before {
            content: counter(ol-counter);
            position: absolute;
            left: 0;
            font-size: 9pt;
            font-weight: 800;
            color: #b45309;
            background: #fef3c7;
            min-width: 16px;
            height: 16px;
            border-radius: 50%;
            text-align: center;
            line-height: 16px;
        }

        .body blockquote {
            border: 1px solid #d97706;
            padding: 8px 14px;
            margin: 10px 0;
            background: #fffbeb;
            border-radius: 0 6px 6px 0;
            color: #78716c;
            font-style: italic;
        }

        .body code {
            background: #fef3c7;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 10pt;
            color: #b45309;
            font-family: 'Courier New', Courier, monospace;
        }

        .body pre {
            background: #1c1917;
            color: #e7e5e4;
            padding: 14px;
            border-radius: 8px;
            font-size: 9pt;
            line-height: 1.5;
            margin: 10px 0;
            overflow-x: auto;
        }
        .body pre code {
            background: none;
            padding: 0;
            color: inherit;
            font-size: inherit;
        }

        .body table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
            font-size: 10pt;
            border: 1px solid #e8e5e0;
            border-radius: 6px;
        }
        .body thead { background: #f5f3f0; }
        .body th {
            padding: 7px 10px;
            text-align: left;
            font-weight: 700;
            color: #78716c;
            font-size: 9pt;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            border-bottom: 2px solid #e8e5e0;
        }
        .body td {
            padding: 7px 10px;
            border-bottom: 1px solid #f0eeeb;
            color: #1c1917;
        }
        .body tr:last-child td { border-bottom: none; }
        .body tr:nth-child(even) td { background: #faf9f7; }

        .body hr {
            border: none;
            height: 1px;
            background: #e8e5e0;
            margin: 14px 0;
        }

        .body a {
            color: #b45309;
            text-decoration: none;
            font-weight: 600;
        }

        /* Footer */
        .footer {
            margin-top: 32px;
            padding-top: 12px;
            border-top: 1px solid #e8e5e0;
            font-size: 8pt;
            color: #a8a29e;
            text-align: center;
        }

        /* Checkbox style */
        .body li input[type="checkbox"] {
            margin-right: 6px;
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="header">
            <div class="header-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2">
                    <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div>
                <h1>Final Report</h1>
                <div class="subtitle">{{ $title }}</div>
            </div>
        </div>

        <div class="meta">
            @if($incident_no)
                <span><strong>Incident:</strong> {{ $incident_no }}</span>
            @endif
            @if($incident_severity)
                <span><strong>Severity:</strong> {{ $incident_severity }}</span>
            @endif
            @if($user_name)
                <span><strong>Analyst:</strong> {{ $user_name }}</span>
            @endif
            @if($tokens_used)
                <span><strong>Tokens:</strong> {{ number_format($tokens_used) }}</span>
            @endif
            <span><strong>Generated:</strong> {{ $generated_at }}</span>
        </div>

        <div class="body">
            {!! $report_html !!}
        </div>

        <div class="footer">
            Generated by AI Retrospective &mdash; TechRisk Dashboard &mdash; {{ $generated_at }}
        </div>
    </div>
</body>
</html>
