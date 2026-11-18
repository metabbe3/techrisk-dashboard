<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Incident Investigation Form</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 10pt; line-height: 1.5; color: #1c1917; }
        .page { padding: 32px 40px; }
        h1 { font-size: 16pt; margin-bottom: 4px; }
        h2 { font-size: 12pt; margin: 18px 0 8px; border-bottom: 1px solid #d6d3d1; padding-bottom: 3px; }
        .muted { color: #78716c; font-size: 9pt; }
        table.meta { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.meta td { border: 1px solid #d6d3d1; padding: 4px 8px; }
        table.meta td.k { width: 30%; color: #57534e; background: #fafaf9; }
        dl { margin-top: 4px; }
        dl dt { color: #57534e; margin-top: 8px; }
        dl dd { margin: 1px 0 0 12px; }
        table.grid { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.grid th, table.grid td { border: 1px solid #d6d3d1; padding: 4px 8px; text-align: left; vertical-align: top; }
        table.grid th { background: #fafaf9; }
        .statement { margin-top: 20px; padding: 12px; border: 1px solid #d6d3d1; background: #fafaf9; }
        .consent { margin-top: 8px; color: #57534e; font-size: 9pt; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>
<div class="page">
    <h1>Incident Investigation Form</h1>
    <p class="muted">Template 2025 v1.0 — submitted {{ now()->format('Y-m-d H:i') }}</p>

    <h2>Incident Information</h2>
    <table class="meta">
        @foreach (($sections['header']['fields'] ?? []) as $fieldKey => $label)
            <tr>
                <td class="k">{{ $label }}</td>
                <td>{{ $draft['header'][$fieldKey] ?? '' }}</td>
            </tr>
        @endforeach
    </table>

    <h2>Incident Summary</h2>
    <dl>
        @foreach (($sections['summary']['fields'] ?? []) as $fieldKey => $label)
            <dt>{{ $label }}</dt>
            <dd>{{ $draft['summary'][$fieldKey] ?? '' }}</dd>
        @endforeach
    </dl>

    <h2>Incident Chronology</h2>
    <table class="grid">
        <thead>
            <tr><th style="width: 22%">Time</th><th>Event</th></tr>
        </thead>
        <tbody>
            @forelse (($draft['chronology'] ?? []) as $row)
                <tr><td>{{ $row['time'] ?? '' }}</td><td>{{ $row['event'] ?? '' }}</td></tr>
            @empty
                <tr><td>&nbsp;</td><td>&nbsp;</td></tr>
            @endforelse
        </tbody>
    </table>

    @foreach ($sections as $sectionKey => $section)
        @continue(in_array($sectionKey, $listSections) || in_array($sectionKey, ['header', 'summary']))
        <h2>{{ $section['label'] }}</h2>
        <table class="grid">
            <tbody>
                @foreach ($section['fields'] as $fieldKey => $label)
                    <tr>
                        <td style="width: 45%">{{ $label }}</td>
                        <td>{{ $draft[$sectionKey][$fieldKey] ?? '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    <h2>Action Improvement</h2>
    <table class="grid">
        <thead>
            <tr><th>Action item</th><th style="width: 15%">Due date</th><th style="width: 25%">Verification standard</th><th style="width: 15%">Owner</th></tr>
        </thead>
        <tbody>
            @forelse (($draft['action_items'] ?? []) as $row)
                <tr>
                    <td>{{ $row['item'] ?? '' }}</td>
                    <td>{{ $row['due_date'] ?? '' }}</td>
                    <td>{{ $row['verification'] ?? '' }}</td>
                    <td>{{ $row['owner'] ?? '' }}</td>
                </tr>
            @empty
                <tr><td>&nbsp;</td><td></td><td></td><td></td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="statement">
        <strong>Statement of Truth</strong>
        <p>{{ $statement }}</p>
        <p class="consent">
            Submitted by {{ $draft['submitter_name'] ?? '' }} — signed the Statement of Truth above.
        </p>
    </div>
</div>
</body>
</html>
