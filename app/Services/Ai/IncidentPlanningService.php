<?php

namespace App\Services\Ai;

use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Incident;
use App\Services\Markdown\MarkdownFormatter;
use Illuminate\Support\Collection;

class IncidentPlanningService
{
    /**
     * Generate an incident assessment plan for AI context injection.
     * Called when incidents are referenced or detected from conversation.
     */
    public function buildIncidentPlan(Incident $incident): string
    {
        $lines = [];
        $lines[] = "### Incident Assessment Plan for {$incident->no}";
        $lines[] = '';

        $priority = $this->assessPriority($incident);
        $lines[] = "**Priority Level:** {$priority['level']} — {$priority['reason']}";

        $triggers = $this->identifyEscalationTriggers($incident);
        if (! empty($triggers)) {
            $lines[] = "\n**Escalation Triggers:**";
            foreach ($triggers as $trigger) {
                $lines[] = "- {$trigger}";
            }
        }

        if (! empty($incident->recurrence_data['matches'])) {
            $lines[] = "\n**Similar Past Incidents:**";
            foreach (array_slice($incident->recurrence_data['matches'], 0, 3) as $match) {
                $no = $match['no'] ?? 'Unknown';
                $score = $match['score'] ?? 'N/A';
                $summary = $match['summary'] ?? '';
                $lines[] = "- [{$no}] Score: {$score}".($summary ? " — {$summary}" : '');
            }
            if (! empty($incident->recurrence_data['ai_analysis'])) {
                $lines[] = "\n**Recurrence Analysis:** ".$incident->recurrence_data['ai_analysis'];
            }
        }

        $actions = $this->suggestResponsePlan($incident);
        if (! empty($actions)) {
            $lines[] = "\n**Suggested Response Plan:**";
            foreach ($actions as $i => $action) {
                $lines[] = "{$i}. {$action}";
            }
        }

        $risks = $this->identifyRisks($incident);
        if (! empty($risks)) {
            $lines[] = "\n**Risk Flags:**";
            foreach ($risks as $risk) {
                $lines[] = "- {$risk}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build plan for multiple incidents (comparative analysis).
     */
    public function buildComparativePlan(Collection $incidents): string
    {
        $lines = [];
        $lines[] = '### Multi-Incident Assessment Plan';
        $lines[] = '';

        $commonRootCauses = $this->findCommonRootCauses($incidents);
        if (! empty($commonRootCauses)) {
            $lines[] = '**Common Root Cause Categories:** '.implode(', ', $commonRootCauses);
        }

        $totalLoss = $incidents->sum('fund_loss');
        if ($totalLoss > 0) {
            $lines[] = '**Combined Financial Impact:** '.MarkdownFormatter::formatMoney((float) $totalLoss);
        }

        $totalPotential = $incidents->sum('potential_fund_loss');
        if ($totalPotential > 0) {
            $lines[] = '**Combined Potential Exposure:** '.MarkdownFormatter::formatMoney((float) $totalPotential);
        }

        $openCount = $incidents->whereNotIn('incident_status', ['Completed'])->count();
        if ($openCount > 0) {
            $lines[] = "**Open/In Progress:** {$openCount} of {$incidents->count()} incidents";
        }

        $allOverdue = $incidents->flatMap->actionImprovements
            ->filter(fn ($a) => ! $a->is_completed && $a->due_date && $a->due_date < now());
        if ($allOverdue->count() > 0) {
            $lines[] = "**Overdue Actions Across Incidents:** {$allOverdue->count()}";
        }

        return implode("\n", $lines);
    }

    private function assessPriority(Incident $incident): array
    {
        return match (true) {
            $incident->severity === Severity::P1 => ['level' => 'CRITICAL', 'reason' => 'P1 severity requires immediate response'],
            $incident->severity === Severity::P2 && $incident->fund_loss > 0 => ['level' => 'HIGH', 'reason' => 'P2 with confirmed fund loss'],
            $incident->severity === Severity::P2 => ['level' => 'HIGH', 'reason' => 'P2 severity needs urgent attention'],
            ! empty($incident->recurrence_data['is_recurring']) => ['level' => 'HIGH', 'reason' => 'Recurring incident pattern detected'],
            $incident->fund_loss > 0 => ['level' => 'ELEVATED', 'reason' => 'Confirmed financial impact'],
            default => ['level' => 'STANDARD', 'reason' => "{$incident->severity->value} severity, standard review recommended"],
        };
    }

    private function identifyEscalationTriggers(Incident $incident): array
    {
        $triggers = [];

        if ($incident->severity === Severity::P1) {
            $triggers[] = 'P1 incident — automatic executive notification required';
        }
        if ($incident->fund_loss > 0 && $incident->severity !== 'P1') {
            $triggers[] = 'Fund loss confirmed — financial review needed';
        }
        if (empty($incident->root_cause)) {
            $triggers[] = 'No root cause analysis yet — RCA should be prioritized';
        }
        if ($incident->actionImprovements->where('is_completed', false)->count() > 3) {
            $triggers[] = 'Multiple pending action improvements — risk of recurrence';
        }
        if (! empty($incident->recurrence_data['is_recurring'])) {
            $triggers[] = 'Pattern recurrence detected — systemic issue likely';
        }
        if ($incident->investigationDocuments->isEmpty() && in_array($incident->severity, [Severity::P1, Severity::P2])) {
            $triggers[] = 'P1/P2 without investigation documents — documentation gap';
        }

        return $triggers;
    }

    private function suggestResponsePlan(Incident $incident): array
    {
        $actions = [];
        $status = $incident->incident_status;

        if ($status === IncidentStatus::Open) {
            $actions[] = 'Assign PIC and begin initial investigation';
            $actions[] = 'Document timeline of events as they are discovered';
        }
        if (empty($incident->root_cause)) {
            $actions[] = 'Conduct root cause analysis (RCA)';
        }
        if ($incident->fund_loss > 0) {
            $actions[] = 'Initiate fund recovery process';
            $actions[] = 'Document financial impact with evidence';
        }
        if (! empty($incident->recurrence_data['is_recurring'])) {
            $actions[] = 'Review past similar incidents for unresolved action items';
            $actions[] = 'Assess systemic gaps allowing recurrence';
        }
        if ($incident->actionImprovements->isEmpty()) {
            $actions[] = 'Define action improvements to prevent recurrence';
        }

        return $actions;
    }

    private function identifyRisks(Incident $incident): array
    {
        $risks = [];

        $overdueActions = $incident->actionImprovements
            ->filter(fn ($a) => ! $a->is_completed && $a->due_date && $a->due_date < now());
        if ($overdueActions->count() > 0) {
            $risks[] = "{$overdueActions->count()} overdue action improvement(s) — risk of recurrence";
        }
        if ($incident->potential_fund_loss > 0 && $incident->recovered_fund == 0) {
            $risks[] = 'Potential fund loss with zero recovery — financial exposure';
        }
        if (in_array($incident->incident_status, [IncidentStatus::Open, IncidentStatus::InProgress]) && $incident->incident_date && $incident->incident_date->diffInDays(now()) > 30) {
            $risks[] = 'Incident open 30+ days — resolution delay risk';
        }

        return $risks;
    }

    private function findCommonRootCauses(Collection $incidents): array
    {
        $allCategories = $incidents->flatMap->root_cause_category->filter()->toArray();

        return array_keys(array_filter(array_count_values($allCategories), fn ($count) => $count > 1));
    }
}
