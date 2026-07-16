<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Post-Mortem — {{ $incident->no }}</title>
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

        /* Table of Contents */
        .toc {
            background: #faf9f7;
            border: 1px solid #e8e5e0;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 28px;
        }
        .toc h2 {
            font-size: 11pt;
            font-weight: 700;
            color: #78716c;
            margin: 0 0 8px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .toc ol {
            margin: 0;
            padding-left: 20px;
        }
        .toc li {
            font-size: 10pt;
            margin-bottom: 3px;
            color: #44403c;
        }
        .toc a {
            color: #b45309;
            text-decoration: none;
        }

        /* Body sections */
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

        .body p { margin: 8px 0; }
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

        /* Impact assessment table */
        .impact-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
            font-size: 10pt;
            border: 1px solid #e8e5e0;
        }
        .impact-table th {
            padding: 8px 12px;
            text-align: left;
            font-weight: 700;
            color: #78716c;
            font-size: 9pt;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            background: #f5f3f0;
            border-bottom: 2px solid #e8e5e0;
            width: 140px;
        }
        .impact-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #f0eeeb;
            color: #1c1917;
        }
        .impact-table tr:last-child td { border-bottom: none; }

        /* Footer */
        .footer {
            margin-top: 32px;
            padding-top: 12px;
            border-top: 1px solid #e8e5e0;
            font-size: 8pt;
            color: #a8a29e;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="header">
            <div class="header-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2">
                    <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <div>
                <h1>Blameless Post-Mortem Report</h1>
                <div class="subtitle">{{ $incident->title }}</div>
            </div>
        </div>

        <div class="meta">
            @if($incident->no)
                <span><strong>Incident:</strong> {{ $incident->no }}</span>
            @endif
            @if($incident->severity)
                <span><strong>Severity:</strong> {{ $incident->severity }}</span>
            @endif
            @if($incident->incident_status)
                <span><strong>Status:</strong> {{ $incident->incident_status }}</span>
            @endif
            @if($incident->pic)
                <span><strong>PIC:</strong> {{ $incident->pic->name }}</span>
            @endif
            <span><strong>Generated:</strong> {{ $generated_at }}</span>
        </div>

        <div class="toc">
            <h2>Table of Contents</h2>
            <ol>
                <li>Executive Summary</li>
                <li>Incident Timeline Analysis</li>
                <li>Root Cause Deep Dive</li>
                <li>Impact Assessment</li>
                <li>Lessons Learned</li>
                <li>Recommended Actions</li>
            </ol>
        </div>

        <div class="body">
            <h1>1. Executive Summary</h1>
            {!! $sections['executive_summary_html'] ?? '<p>Not available.</p>' !!}

            <h1>2. Incident Timeline Analysis</h1>
            {!! $sections['timeline_analysis_html'] ?? '<p>Not available.</p>' !!}

            <h1>3. Root Cause Deep Dive</h1>
            {!! $sections['root_cause_deep_dive_html'] ?? '<p>Not available.</p>' !!}

            <h1>4. Impact Assessment</h1>
            @if(!empty($sections['impact_assessment']))
                <table class="impact-table">
                    <tr>
                        <th>Users Affected</th>
                        <td>{{ $sections['impact_assessment']['users_affected'] ?? 'Not assessed.' }}</td>
                    </tr>
                    <tr>
                        <th>Systems Affected</th>
                        <td>{{ $sections['impact_assessment']['systems_affected'] ?? 'Not assessed.' }}</td>
                    </tr>
                    <tr>
                        <th>Financial Impact</th>
                        <td>{{ $sections['impact_assessment']['financial_impact'] ?? 'Not assessed.' }}</td>
                    </tr>
                    <tr>
                        <th>Reputation Impact</th>
                        <td>{{ $sections['impact_assessment']['reputation_impact'] ?? 'Not assessed.' }}</td>
                    </tr>
                </table>
            @else
                <p>Not available.</p>
            @endif

            <h1>5. Lessons Learned</h1>
            @if(!empty($sections['lessons_learned']))
                <ol>
                    @foreach($sections['lessons_learned'] as $lesson)
                        <li>{{ $lesson }}</li>
                    @endforeach
                </ol>
            @else
                <p>No lessons learned documented.</p>
            @endif

            <h1>6. Recommended Actions</h1>
            @if(!empty($sections['recommendations']))
                <ol>
                    @foreach($sections['recommendations'] as $recommendation)
                        <li>{{ $recommendation }}</li>
                    @endforeach
                </ol>
            @else
                <p>No recommendations available.</p>
            @endif

            @if($sections['severity_assessment'] ?? '')
                <hr>
                <p><strong>Severity Assessment:</strong> {{ $sections['severity_assessment'] }}</p>
            @endif
        </div>

        <div class="footer">
            Generated by Post-Mortem Report &mdash; TechRisk Dashboard &mdash; {{ $generated_at }}
        </div>
    </div>
</body>
</html>
