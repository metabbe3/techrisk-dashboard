---
title: {{ $incident->title }}
incident_id: {{ $incident->id }}
incident_no: {{ $incident->no }}
classification: {{ $incident->classification }}
severity: {{ $incident->severity }}
status: {{ $incident->incident_status }}
exported_at: {{ now()->format('Y-m-d H:i:s') }}
---

# {{ $incident->title }}

**Incident ID:** `{{ $incident->no }}`
**Classification:** {{ $incident->classification }}
**Severity:** {{ $incident->severity }}
**Status:** {{ $incident->incident_status }}
**Incident Type:** {{ $incident->incidentType?->name ?? 'N/A' }}
**Area:** {{ $incident->incident_type ?? 'N/A' }}
**Source:** {{ $incident->incident_source ?? 'N/A' }}

## Summary

{{ $incident->summary ?? 'No summary provided.' }}

## Timeline and Chronology

| Field | Value |
|-------|-------|
| **Incident Date** | {{ \App\Services\Markdown\MarkdownFormatter::formatDate($incident->incident_date) }} |
| **Discovered At** | {{ \App\Services\Markdown\MarkdownFormatter::formatDate($incident->discovered_at) }} |
| **Stop Bleeding At** | {{ \App\Services\Markdown\MarkdownFormatter::formatDate($incident->stop_bleeding_at) }} |
| **Entry Date (Tech Risk)** | {{ \App\Services\Markdown\MarkdownFormatter::formatDate($incident->entry_date_tech_risk) }} |

### Timeline Details

{{ $incident->timeline ?? 'No timeline provided.' }}

## Root Cause Analysis

{{ $incident->root_cause ?? 'No root cause analysis provided.' }}

## Financial Impact

| Metric | Amount |
|--------|--------|
| **Fund Status** | {{ $incident->fund_status ?? 'N/A' }} |
| **Potential Fund Loss** | {{ \App\Services\Markdown\MarkdownFormatter::formatMoney($incident->potential_fund_loss) }} |
| **Recovered Fund** | {{ \App\Services\Markdown\MarkdownFormatter::formatMoney($incident->recovered_fund) }} |
| **Actual Fund Loss** | {{ \App\Services\Markdown\MarkdownFormatter::formatMoney($incident->fund_loss) }} |
| **Loss Taken By** | {{ $incident->loss_taken_by ?? 'N/A' }} |

## Performance Metrics

| Metric | Value |
|--------|-------|
| **MTTR** | {{ $incident->mttr ? \App\Services\Markdown\MarkdownFormatter::formatDuration($incident->mttr) : 'N/A' }} |
| **MTBF** | {{ $incident->mtbf ? number_format($incident->mtbf, 2).' days' : 'N/A' }} |

## Responsible Parties

| Role | Name/Details |
|------|--------------|
| **Person In Charge** | @if($incident->pic){{ $incident->pic->name }} ({{ $incident->pic->email }})@else N/A @endif |
| **Reported By** | {{ $incident->reported_by ?? 'N/A' }} |
| **Third Party/Client** | {{ $incident->third_party_client ?? 'N/A' }} |
| **People Caused** | @if($incident->people_caused && is_array($incident->people_caused)){{ implode(', ', $incident->people_caused) }}@elseif($incident->people_caused){{ $incident->people_caused }}@else N/A @endif |
| **Checker** | {{ $incident->checker ?? 'N/A' }} |
| **Maker** | {{ $incident->maker ?? 'N/A' }} |

## Labels

@if($incident->labels->count() > 0)
@foreach($incident->labels as $label)
- `{{ $label->name }}`
@endforeach
@else
No labels assigned.
@endif

## Status Updates

@if($incident->statusUpdates->count() > 0)
@foreach($incident->statusUpdates as $update)
### {{ \App\Services\Markdown\MarkdownFormatter::formatDate($update->created_at) }} - {{ $update->status }}

{{ $update->notes ?? 'No notes provided.' }}

@endforeach
@else
No status updates recorded.
@endif

## Action Items & Improvements

@if($incident->actionImprovements->count() > 0)
@foreach($incident->actionImprovements as $action)
### {{ $action->title ?? 'Untitled Action' }}

{{ $action->detail ?? 'No details provided.' }}

| Field | Value |
|-------|-------|
| **Due Date** | {{ \App\Services\Markdown\MarkdownFormatter::formatDate($action->due_date) }} |
| **PIC Email(s)** | @if(is_array($action->pic_email)){{ implode(', ', $action->pic_email) }}@else {{ $action->pic_email ?? 'N/A' }} @endif |
| **Status** | {{ $action->status ?? 'N/A' }} |
| **Reminder** | {{ $action->reminder ? 'Yes (' . $action->reminder_frequency . ')' : 'No' }} |
| **Is Completed** | {{ \App\Services\Markdown\MarkdownFormatter::formatBool($action->is_completed) }} |

@endforeach
@else
No action items defined.
@endif

## Investigation Documents

@if($incident->investigationDocuments->count() > 0)
@foreach($incident->investigationDocuments as $doc)
### {{ $doc->original_filename ?? 'Document' }}

| Field | Value |
|-------|-------|
| **Description** | {{ $doc->description ?? 'N/A' }} |
| **PIC Status** | {{ $doc->pic_status ?? 'N/A' }} |
| **Uploaded At** | {{ \App\Services\Markdown\MarkdownFormatter::formatDate($doc->created_at) }} |
| **Markdown Available** | {{ $doc->markdown_path ? 'Yes' : 'No' }} |

@endforeach
@else
No investigation documents attached.
@endif

## Evidence

@if($incident->evidence)
### Evidence Details
{{ $incident->evidence }}
@endif

@if($incident->evidence_link)
### Evidence Link
{{ $incident->evidence_link }}
@endif

## Remarks

{{ $incident->remark ?? 'No remarks provided.' }}

## Administrative Status

| Status Flag | Value |
|-------------|-------|
| **GoC Uploaded** | {{ \App\Services\Markdown\MarkdownFormatter::formatBool($incident->goc_upload) }} |
| **Teams Uploaded** | {{ \App\Services\Markdown\MarkdownFormatter::formatBool($incident->teams_upload) }} |
| **Doc Signed** | {{ \App\Services\Markdown\MarkdownFormatter::formatBool($incident->doc_signed) }} |
| **Risk Incident Form CFM** | {{ \App\Services\Markdown\MarkdownFormatter::formatBool($incident->risk_incident_form_cfm) }} |
| **Glitch Flag** | {{ $incident->glitch_flag ?? 'N/A' }} |

---

*Exported from Technical Risk Dashboard on {{ now()->format('Y-m-d H:i:s') }}*
