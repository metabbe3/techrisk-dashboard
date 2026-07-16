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
            font-size: 10pt;
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
            flex-shrink: 0;
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
            flex-wrap: wrap;
            gap: 20px;
            padding: 12px 0;
            border-bottom: 1px solid #e8e5e0;
            margin-bottom: 24px;
            font-size: 9pt;
            color: #78716c;
        }
        .meta span { display: inline-flex; align-items: center; gap: 4px; }
        .meta strong { color: #1c1917; }

        /* Chat messages */
        .chat { display: flex; flex-direction: column; gap: 16px; }

        .msg { border-radius: 10px; overflow: hidden; }
        .msg-user { background: #fffbeb; border: 1px solid #d97706; }
        .msg-assistant { background: #f5f3f0; border: 1px solid #a8a29e; }
        .msg-assistant.has-persona { border-left-color: var(--persona-color, #a8a29e); }

        .msg-header {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px 6px;
            font-size: 9pt;
        }
        .msg-avatar {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 8pt;
            font-weight: 700;
            flex-shrink: 0;
        }
        .msg-avatar.user-avatar {
            background: #fef3c7;
            color: #b45309;
        }
        .msg-avatar.ai-avatar {
            background: #1c1917;
            color: #f59e0b;
        }
        .msg-sender {
            font-weight: 700;
            color: #1c1917;
        }
        .msg-time {
            margin-left: auto;
            color: #a8a29e;
            font-size: 8pt;
        }
        .msg-persona-badge {
            font-size: 8pt;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 4px;
            background: #fef3c7;
            color: #b45309;
        }

        .msg-body {
            padding: 4px 14px 12px;
        }

        /* User message — plain text */
        .msg-user .msg-body p {
            font-size: 10pt;
            line-height: 1.6;
            color: #1c1917;
        }

        /* Assistant message — rich markdown */
        .msg-body h1 { font-size: 14pt; font-weight: 800; color: #1c1917; margin: 16px 0 8px; padding-bottom: 4px; border-bottom: 2px solid #d97706; }
        .msg-body h1:first-child { margin-top: 0; }
        .msg-body h2 { font-size: 12pt; font-weight: 700; color: #1c1917; margin: 14px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #e8e5e0; }
        .msg-body h2:first-child { margin-top: 0; }
        .msg-body h3 { font-size: 11pt; font-weight: 700; color: #1c1917; margin: 10px 0 4px; }
        .msg-body h4 { font-size: 10pt; font-weight: 700; color: #78716c; margin: 8px 0 4px; }
        .msg-body p { margin: 6px 0; }
        .msg-body strong { font-weight: 700; color: #1c1917; }
        .msg-body em { font-style: italic; color: #78716c; }

        .msg-body ul { list-style: none; padding-left: 0; margin: 6px 0; }
        .msg-body ul li { position: relative; padding-left: 14px; margin-bottom: 3px; }
        .msg-body ul li::before { content: '\2022'; position: absolute; left: 0; color: #d97706; font-weight: 700; }

        .msg-body ol { list-style: none; counter-reset: ol-counter; padding-left: 0; margin: 6px 0; }
        .msg-body ol li { position: relative; padding-left: 22px; margin-bottom: 3px; counter-increment: ol-counter; }
        .msg-body ol li::before {
            content: counter(ol-counter);
            position: absolute; left: 0;
            font-size: 8pt; font-weight: 800; color: #b45309;
            background: #fef3c7;
            min-width: 14px; height: 14px; border-radius: 50%;
            text-align: center; line-height: 14px;
        }

        .msg-body blockquote { border: 1px solid #d97706; padding: 6px 12px; margin: 8px 0; background: #fffbeb; border-radius: 0 6px 6px 0; color: #78716c; font-style: italic; }
        .msg-body code { background: #fef3c7; padding: 1px 4px; border-radius: 3px; font-size: 9pt; color: #b45309; font-family: 'Courier New', Courier, monospace; }
        .msg-body pre { background: #1c1917; color: #e7e5e4; padding: 12px; border-radius: 6px; font-size: 8pt; line-height: 1.5; margin: 8px 0; overflow-x: auto; }
        .msg-body pre code { background: none; padding: 0; color: inherit; font-size: inherit; }

        .msg-body table { width: 100%; border-collapse: collapse; margin: 8px 0; font-size: 9pt; border: 1px solid #e8e5e0; }
        .msg-body thead { background: #f5f3f0; }
        .msg-body th { padding: 5px 8px; text-align: left; font-weight: 700; color: #78716c; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.03em; border-bottom: 2px solid #e8e5e0; }
        .msg-body td { padding: 5px 8px; border-bottom: 1px solid #f0eeeb; color: #1c1917; }
        .msg-body tr:last-child td { border-bottom: none; }
        .msg-body tr:nth-child(even) td { background: #faf9f7; }

        .msg-body hr { border: none; height: 1px; background: #e8e5e0; margin: 10px 0; }
        .msg-body a { color: #b45309; text-decoration: none; font-weight: 600; }

        /* Message meta tags */
        .msg-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 6px 14px 10px;
        }
        .msg-tag {
            font-size: 8pt;
            padding: 2px 6px;
            border-radius: 3px;
            background: #e8e5e0;
            color: #78716c;
        }
        .msg-tag.search-tag { background: #d1fae5; color: #065f46; }

        /* Footer */
        .footer {
            margin-top: 28px;
            padding-top: 12px;
            border-top: 1px solid #e8e5e0;
            font-size: 8pt;
            color: #a8a29e;
            text-align: center;
        }

        /* Page break avoidance */
        .msg { page-break-inside: avoid; }
    </style>
</head>
<body>
    <div class="page">
        <div class="header">
            <div class="header-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2">
                    <path d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"/>
                </svg>
            </div>
            <div>
                <h1>TechRisk AI &mdash; Chat Export</h1>
                <div class="subtitle">{{ $title }}</div>
            </div>
        </div>

        <div class="meta">
            @if($user_name)
                <span><strong>User:</strong> {{ $user_name }}</span>
            @endif
            @if($model)
                <span><strong>Model:</strong> {{ $model }}</span>
            @endif
            @if($total_tokens > 0)
                <span><strong>Total Tokens:</strong> {{ number_format($total_tokens) }}</span>
            @endif
            <span><strong>Messages:</strong> {{ $messages->count() }}</span>
            @if(count($personas_used) > 0)
                <span><strong>Personas:</strong> {{ implode(', ', $personas_used) }}</span>
            @endif
            <span><strong>Exported:</strong> {{ $exported_at }}</span>
        </div>

        <div class="chat">
            @foreach($messages as $msg)
                @if($msg->role === 'user')
                    <div class="msg msg-user">
                        <div class="msg-header">
                            <div class="msg-avatar user-avatar">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            </div>
                            <span class="msg-sender">{{ $user_name ?? 'User' }}</span>
                            @if($msg->created_at)
                                <span class="msg-time">{{ $msg->created_at->format('H:i') }}</span>
                            @endif
                        </div>
                        <div class="msg-body">
                            <p>{{ $msg->content }}</p>
                        </div>
                    </div>
                @elseif($msg->role === 'assistant')
                    <div class="msg msg-assistant {{ $msg->persona_name ? 'has-persona' : '' }}" @if($msg->persona_color) style="--persona-color: {{ $msg->persona_color }}" @endif>
                        <div class="msg-header">
                            <div class="msg-avatar ai-avatar">
                                @if($msg->persona_name)
                                    {{ strtoupper(mb_substr($msg->persona_name, 0, 1)) }}
                                @else
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2"><path d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/></svg>
                                @endif
                            </div>
                            <span class="msg-sender">{{ $msg->persona_name ?? 'TechRisk AI' }}</span>
                            @if($msg->persona_name)
                                <span class="msg-persona-badge" @if($msg->persona_color) style="background: {{ $msg->persona_color }}22; color: {{ $msg->persona_color }}" @endif>persona</span>
                            @endif
                            @if($msg->created_at)
                                <span class="msg-time">{{ $msg->created_at->format('H:i') }}</span>
                            @endif
                        </div>
                        <div class="msg-body">
                            {!! $msg->parsed_html ?? '' !!}
                        </div>
                        <div class="msg-meta">
                            @if($msg->model)
                                <span class="msg-tag">{{ $msg->model }}</span>
                            @endif
                            @if($msg->tokens_used)
                                <span class="msg-tag">{{ number_format($msg->tokens_used) }} tokens</span>
                            @endif
                            @if($msg->web_search_used)
                                <span class="msg-tag search-tag">Web Search</span>
                            @endif
                        </div>
                    </div>
                @endif
            @endforeach
        </div>

        <div class="footer">
            Exported from TechRisk AI &mdash; TechRisk Dashboard &mdash; {{ $exported_at }}
        </div>
    </div>
</body>
</html>
