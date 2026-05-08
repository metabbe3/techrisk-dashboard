<?php

namespace App\Services\Ai;

use App\Enums\FundStatus;
use App\Enums\Severity;
use App\Models\Incident;
use Illuminate\Support\Facades\Cache;

class ChatContextService
{
    public function buildSystemPrompt(string $userMessage, array $referencedIds = []): string
    {
        $prompt = config('ai.prompts.chat_assistant.system', '');
        $prompt = str_replace('{current_date}', now()->format('Y-m-d'), $prompt);

        $enriched = $this->enrichContext($userMessage, $referencedIds);
        $hasReferenced = ! empty($referencedIds) || preg_match('/\d{4}_(?:IN|IS)_\d{4}/', $userMessage);
        $refCount = count($referencedIds) + preg_match_all('/\d{4}_(?:IN|IS)_\d{4}/', $userMessage);

        // When 2+ incidents referenced, skip recent incidents to save tokens
        $skipRecent = $hasReferenced && $refCount >= 2;

        $stats = $this->getQuickStats();
        $recent = $skipRecent ? null : $this->getRecentIncidents();

        $context = "\n\n--- CURRENT DATA CONTEXT ---\n\n";

        if ($hasReferenced) {
            $context .= "## ⚠️ PRIORITY: USER-REFERENCED INCIDENTS\n"
                ."The user has specifically attached/referenced one or more incidents. "
                ."Focus your response PRIMARILY on these specific incidents. "
                ."Do NOT provide a general dashboard overview unless explicitly asked.\n\n";
            if ($enriched) {
                $context .= "{$enriched}\n\n";
            }
            $context .= "---\n\n"
                ."## Background Data (for reference only)\n\n"
                ."### Quick Stats (this year)\n{$stats}\n";
            if ($recent) {
                $context .= "\n### Recent Incidents\n{$recent}\n";
            }
        } else {
            $context .= "## Quick Stats (this year)\n{$stats}\n\n";
            $context .= "## Recent Incidents (last 10)\n{$recent}\n";

            if ($enriched) {
                $context .= "\n## Additional Context (based on your question)\n{$enriched}\n";
            }
        }

        return $prompt.$context;
    }

    public function getQuickStats(): string
    {
        return Cache::remember('chat_quick_stats_v2', 300, function () {
            $year = now()->year;
            $excludeQ = fn ($q) => $q->whereNull('fund_status')->orWhereNotIn('fund_status', FundStatus::EXCLUDED_FROM_COUNTS);

            $totalIncidents = Incident::where('classification', 'Incident')
                ->whereYear('incident_date', $year)
                ->where($excludeQ)
                ->count();

            $openIncidents = Incident::where('classification', 'Incident')
                ->whereYear('incident_date', $year)
                ->whereNotIn('incident_status', ['Completed'])
                ->where($excludeQ)
                ->count();

            $totalFundLoss = Incident::whereYear('incident_date', $year)
                ->where($excludeQ)
                ->sum('fund_loss');

            $avgMttr = Incident::whereYear('incident_date', $year)
                ->whereIn('severity', Severity::METRIC_ELIGIBLE)
                ->where('mttr', '>=', 0)
                ->where($excludeQ)
                ->avg('mttr');

            $bySeverity = Incident::where('classification', 'Incident')
                ->whereYear('incident_date', $year)
                ->whereIn('severity', Severity::METRIC_ELIGIBLE)
                ->where($excludeQ)
                ->selectRaw('severity, COUNT(*) as count')
                ->groupBy('severity')
                ->pluck('count', 'severity')
                ->toArray();

            $byStatus = Incident::where('classification', 'Incident')
                ->whereYear('incident_date', $year)
                ->where($excludeQ)
                ->selectRaw('incident_status, COUNT(*) as count')
                ->groupBy('incident_status')
                ->pluck('count', 'incident_status')
                ->toArray();

            $topLabels = Incident::whereYear('incident_date', $year)
                ->where($excludeQ)
                ->with('labels')
                ->get()
                ->flatMap->labels
                ->groupBy('name')
                ->map->count()
                ->sortDesc()
                ->take(10)
                ->toArray();

            $lines = [
                "Total Incidents ({$year}): {$totalIncidents}",
                "Open/In Progress: {$openIncidents}",
                "Total Fund Loss: Rp " . number_format($totalFundLoss, 0, ',', '.'),
                "Avg MTTR: " . number_format($avgMttr ?? 0, 1) . " minutes",
            ];

            if (! empty($bySeverity)) {
                $lines[] = 'By Severity: ' . collect($bySeverity)->map(fn ($c, $s) => "{$s}={$c}")->implode(', ');
            }

            if (! empty($byStatus)) {
                $lines[] = 'By Status: ' . collect($byStatus)->map(fn ($c, $s) => "{$s}={$c}")->implode(', ');
            }

            if (! empty($topLabels)) {
                $lines[] = 'Top Labels: ' . collect($topLabels)->map(fn ($c, $n) => "{$n}={$c}")->implode(', ');
            }

            // Root cause category breakdown
            $rootCauseCategories = Incident::whereYear('incident_date', $year)
                ->where($excludeQ)
                ->whereNotNull('root_cause_category')
                ->get()
                ->flatMap->root_cause_category
                ->groupBy(fn ($cat) => $cat)
                ->map->count()
                ->sortDesc()
                ->take(10);

            if ($rootCauseCategories->isNotEmpty()) {
                $lines[] = 'Root Cause Categories: ' . $rootCauseCategories->map(fn ($c, $n) => "{$n} ({$c}x)")->implode(', ');
            }

            // Responsible team breakdown
            $responsibleTeams = Incident::whereYear('incident_date', $year)
                ->where($excludeQ)
                ->whereNotNull('responsible_team')
                ->get()
                ->flatMap->responsible_team
                ->groupBy(fn ($team) => $team)
                ->map->count()
                ->sortDesc()
                ->take(10);

            if ($responsibleTeams->isNotEmpty()) {
                $lines[] = 'Responsible Teams: ' . $responsibleTeams->map(fn ($c, $n) => "{$n} ({$c}x)")->implode(', ');
            }

            // Business category breakdown
            $businessCategories = Incident::whereYear('incident_date', $year)
                ->where($excludeQ)
                ->whereNotNull('business_category')
                ->get()
                ->flatMap->business_category
                ->groupBy(fn ($cat) => $cat)
                ->map->count()
                ->sortDesc()
                ->take(10);

            if ($businessCategories->isNotEmpty()) {
                $lines[] = 'Business Categories: ' . $businessCategories->map(fn ($c, $n) => "{$n} ({$c}x)")->implode(', ');
            }

            return implode("\n", $lines);
        });
    }

    public function getRecentIncidents(): string
    {
        return Cache::remember('chat_recent_incidents_v2', 300, function () {
            $incidents = Incident::where('classification', 'Incident')
                ->where(fn ($q) => $q->whereNull('fund_status')->orWhereNotIn('fund_status', FundStatus::EXCLUDED_FROM_COUNTS))
                ->with(['pic', 'labels', 'actionImprovements', 'investigationDocuments'])
                ->latest('incident_date')
                ->take(8)
                ->get();

            if ($incidents->isEmpty()) {
                return 'No incidents found.';
            }

            return $incidents->map(function ($inc) {
                $labels = $inc->labels->pluck('name')->implode(', ') ?: 'None';
                $pic = $inc->pic?->name ?? 'Unassigned';
                $fundLoss = $inc->fund_loss > 0 ? " | Fund Loss: Rp " . number_format($inc->fund_loss, 0, ',', '.') : '';
                $potentialLoss = $inc->potential_fund_loss > 0 ? " | Potential Loss: Rp " . number_format($inc->potential_fund_loss, 0, ',', '.') : '';
                $rootCause = $inc->root_cause ? ' | Root Cause: ' . $inc->root_cause : '';
                $rootCauseCat = $inc->root_cause_category ? ' | RC Categories: ' . implode(', ', $inc->root_cause_category) : '';
                $team = $inc->responsible_team ? ' | Team: ' . implode(', ', $inc->responsible_team) : '';
                $bizCat = $inc->business_category ? ' | Biz Category: ' . (is_array($inc->business_category) ? implode(', ', $inc->business_category) : $inc->business_category) : '';
                $summary = $inc->summary ? ' | Summary: ' . $inc->summary : '';

                $actions = $inc->actionImprovements->isNotEmpty()
                    ? ' | Actions: ' . $inc->actionImprovements->map(fn ($a) => "[{$a->status}] {$a->title}" . ($a->due_date ? ' (due: ' . (is_string($a->due_date) ? $a->due_date : $a->due_date->format('Y-m-d')) . ')' : ''))->implode('; ')
                    : '';

                $docs = $inc->investigationDocuments->isNotEmpty()
                    ? ' | Docs: ' . $inc->investigationDocuments->map(fn ($d) => "\"{$d->original_filename}\"")->implode(', ')
                    : '';

                return "- [{$inc->no}](/admin/incidents/{$inc->id}) {$inc->title} | id:{$inc->id} | {$inc->classification} | {$inc->incident_type} | Severity: {$inc->severity} | Status: {$inc->incident_status} | PIC: {$pic} | Date: {$inc->incident_date?->format('Y-m-d')}{$fundLoss}{$potentialLoss} | MTTR: {$inc->mttr} | MTBF: {$inc->mtbf} | Labels: {$labels}{$summary}{$rootCause}{$rootCauseCat}{$team}{$bizCat}{$actions}{$docs}";
            })->implode("\n");
        });
    }

    public function enrichContext(string $userMessage, array $referencedIds = []): string
    {
        $parts = [];
        $msg = strtolower($userMessage);

        // Detect ALL incident IDs from message text (format: YYYYMMDD_IN/IS_NNNN)
        preg_match_all('/\d{4}_(?:IN|IS)_\d{4}/', $userMessage, $textMatches);
        $allIncidentNos = array_unique(array_merge($textMatches[0], $referencedIds));

        if (! empty($allIncidentNos)) {
            $incidents = Incident::whereIn('no', $allIncidentNos)
                ->with(['pic', 'labels', 'actionImprovements', 'statusUpdates', 'investigationDocuments'])
                ->get();

            foreach ($incidents as $incident) {
                $cats = implode(', ', array_filter([$incident->business_category, $incident->root_cause_category, $incident->responsible_team]));
                $actions = $incident->actionImprovements->isNotEmpty()
                    ? "\nAction Improvements: ".$incident->actionImprovements->map(fn ($a) => "[{$a->status}] {$a->title}".($a->due_date ? ' (due: '.(is_string($a->due_date) ? $a->due_date : $a->due_date->format('Y-m-d')).')' : ''))->implode('; ')
                    : '';
                $docs = $incident->investigationDocuments->isNotEmpty()
                    ? "\nInvestigation Documents: ".$incident->investigationDocuments->map(fn ($d) => "\"{$d->original_filename}\"".($d->description ? " - {$d->description}" : ''))->implode('; ')
                    : '';

                $parts[] = "## Referenced Incident: [{$incident->no}](/admin/incidents/{$incident->id})\n"
                    ."ID: {$incident->id}\n"
                    ."URL: /admin/incidents/{$incident->id}\n"
                    ."Title: {$incident->title}\n"
                    ."Severity: {$incident->severity}\n"
                    ."Status: {$incident->incident_status}\n"
                    ."Classification: {$incident->classification} | Type: {$incident->incident_type}\n"
                    ."Fund Status: ".($incident->fund_status ?? 'N/A')."\n"
                    ."PIC: ".($incident->pic?->name ?? 'Unassigned')."\n"
                    ."Date: {$incident->incident_date?->format('Y-m-d H:i')}\n"
                    ."Summary: ".($incident->summary ?? 'N/A')."\n"
                    ."Root Cause: ".($incident->root_cause ?? 'N/A')."\n"
                    ."Root Cause Category: ".($incident->root_cause_category ? implode(', ', $incident->root_cause_category) : 'N/A')."\n"
                    ."Responsible Team: ".($incident->responsible_team ? implode(', ', $incident->responsible_team) : 'N/A')."\n"
                    ."Timeline: ".($incident->timeline ?? 'N/A')."\n"
                    ."MTTR: {$incident->mttr} | MTBF: {$incident->mtbf}\n"
                    ."Potential Loss: Rp ".number_format((float) ($incident->potential_fund_loss ?? 0), 0, ',', '.')."\n"
                    ."Actual Loss: Rp ".number_format((float) ($incident->fund_loss ?? 0), 0, ',', '.')."\n"
                    ."Recovered: Rp ".number_format((float) ($incident->recovered_fund ?? 0), 0, ',', '.')."\n"
                    ."Labels: ".$incident->labels->pluck('name')->implode(', ')."\n"
                    ."Categories: {$cats}"
                    .$actions
                    .$docs;
            }

            if (count($allIncidentNos) > 1) {
                $parts[] = "**Note**: The user has referenced ".count($incidents)." specific incidents. Focus your analysis primarily on these referenced incidents, comparing and cross-referencing them. Provide a comparative analysis when multiple incidents are referenced.";
            }
        }

        // Detect trend/pattern questions
        if (str_contains($msg, 'trend') || str_contains($msg, 'pattern') || str_contains($msg, 'month') || str_contains($msg, 'over time')) {
            $monthly = Incident::where(fn ($q) => $q->whereNull('fund_status')->orWhereNotIn('fund_status', FundStatus::EXCLUDED_FROM_COUNTS))
                ->selectRaw("MONTH(incident_date) as m, COUNT(*) as cnt, SUM(fund_loss) as loss")
                ->whereYear('incident_date', now()->year)
                ->groupByRaw('MONTH(incident_date)')
                ->orderBy('m')
                ->get();

            $parts[] = "## Monthly Trend (this year)\n" . $monthly->map(fn ($r) => "Month {$r->m}: {$r->cnt} incidents, Rp " . number_format($r->loss ?? 0, 0, ',', '.') . ' fund loss')->implode("\n");
        }

        // Detect similar/pattern questions
        if (str_contains($msg, 'similar') || str_contains($msg, 'recurr') || str_contains($msg, 'repeat')) {
            $topRootCauses = Incident::whereNotNull('root_cause_category')
                ->whereYear('incident_date', now()->year)
                ->get()
                ->flatMap(fn ($inc) => $inc->root_cause_category ?? [])
                ->groupBy(fn ($cat) => $cat)
                ->map->count()
                ->sortDesc()
                ->take(5);

            $topTypes = Incident::whereYear('incident_date', now()->year)
                ->selectRaw('incident_type, COUNT(*) as cnt')
                ->groupBy('incident_type')
                ->orderByDesc('cnt')
                ->take(5)
                ->pluck('cnt', 'incident_type');

            $parts[] = "## Recurring Patterns\n"
                . "Root Cause Categories: " . $topRootCauses->map(fn ($c, $n) => "{$n} ({$c}x)")->implode(', ') . "\n"
                . "Incident Types: " . $topTypes->map(fn ($c, $t) => "{$t} ({$c}x)")->implode(', ');
        }

        // Detect PIC/team questions
        if (str_contains($msg, 'pic') || str_contains($msg, 'person') || str_contains($msg, 'team') || str_contains($msg, 'who')) {
            $topPics = Incident::whereYear('incident_date', now()->year)
                ->where(fn ($q) => $q->whereNull('fund_status')->orWhereNotIn('fund_status', FundStatus::EXCLUDED_FROM_COUNTS))
                ->with('pic')
                ->get()
                ->groupBy(fn ($inc) => $inc->pic?->name ?? 'Unassigned')
                ->map->count()
                ->sortDesc()
                ->take(10);

            $parts[] = "## PIC Distribution\n" . $topPics->map(fn ($c, $n) => "{$n}: {$c} incidents")->implode("\n");
        }

        // Detect RCA / root cause / investigation / document questions
        if (str_contains($msg, 'rca') || str_contains($msg, 'root cause') || str_contains($msg, 'analysis') || str_contains($msg, 'investigation') || str_contains($msg, 'document') || str_contains($msg, 'evidence')) {
            $recentWithRca = Incident::whereYear('incident_date', now()->year)
                ->where(fn ($q) => $q->whereNull('fund_status')->orWhereNotIn('fund_status', FundStatus::EXCLUDED_FROM_COUNTS))
                ->whereNotNull('root_cause')
                ->with(['actionImprovements', 'investigationDocuments'])
                ->latest('incident_date')
                ->take(5)
                ->get();

            if ($recentWithRca->isNotEmpty()) {
                $rcaData = $recentWithRca->map(function ($inc) {
                    $actions = $inc->actionImprovements->isNotEmpty()
                        ? ' | Actions: ' . $inc->actionImprovements->map(fn ($a) => "[{$a->status}] {$a->title}")->implode(', ')
                        : '';
                    $docs = $inc->investigationDocuments->isNotEmpty()
                        ? ' | Docs: ' . $inc->investigationDocuments->map(fn ($d) => "\"{$d->original_filename}\"")->implode(', ')
                        : '';

                    return "- [{$inc->no}](/admin/incidents/{$inc->id}) (id:{$inc->id}): Root Cause: " . $inc->root_cause
                        . " | Categories: " . ($inc->root_cause_category ? implode(', ', $inc->root_cause_category) : 'N/A')
                        . " | Team: " . ($inc->responsible_team ? implode(', ', $inc->responsible_team) : 'N/A')
                        . $actions . $docs;
                })->implode("\n");

                $parts[] = "## Recent RCA Data (incidents with root cause analysis)\n{$rcaData}";
            }
        }

        // Detect summary/overview/executive questions
        if (str_contains($msg, 'summary') || str_contains($msg, 'overview') || str_contains($msg, 'briefing') || str_contains($msg, 'executive') || str_contains($msg, 'report')) {
            $parts[] = $this->getExecutiveSummaryContext();
        }

        return implode("\n\n", $parts);
    }

    public function enrichSlashCommand(string $command, string $args, array $referencedIds = []): array
    {
        $extraContext = match ($command) {
            'summary' => $this->getSummaryContext($args),
            'compare' => $this->getCompareContext($args),
            'risk' => $this->getRiskContext(),
            'search' => $this->getSearchContext($args, $referencedIds),
            'find' => '',
            'analyze' => '',
            default => '',
        };

        $transformedMessage = match ($command) {
            'summary' => $args
                ? "Provide a comprehensive executive summary of incidents for {$args}. Include: total count, severity breakdown, top root causes, financial impact, key trends, and recommendations."
                : "Provide a comprehensive executive summary of incidents for this month. Include: total count, severity breakdown, top root causes, financial impact, key trends, and recommendations.",
            'compare' => $args
                ? "Compare incident data between {$args}. Highlight differences in count, severity, root causes, financial impact, and key changes."
                : "Compare incident data between this month and last month. Highlight differences in count, severity, root causes, financial impact, and key changes.",
            'risk' => "Provide a current risk overview. Include: top active risks, open P1/P2 incidents with links, overdue action improvements, largest fund losses, and risk trend assessment.",
            'search' => $args
                ? "I searched the web for \"{$args}\". Based on the web search results and our internal incident data below, provide a comprehensive answer. Combine external references with our internal incident patterns. Always cite the external sources using markdown links."
                : "I searched the web. Based on the web search results and our internal incident data below, provide a comprehensive answer.",
            'find' => $args ? "Find incidents matching: {$args}" : "Find incidents matching my query.",
            'analyze' => $args ? "Provide a deep analysis of incident {$args}. Include full root cause analysis, timeline, financial impact, action improvements, investigation documents, and recommendations." : "Provide a deep analysis of the most critical incident.",
            default => $args ?: 'Help me with my incidents.',
        };

        return [
            'message' => $transformedMessage,
            'extra_context' => $extraContext,
        ];
    }

    /**
     * Handle inline search triggers like "search the web for..." anywhere in the message.
     */
    public function getSearchContextFromMessage(string $message, array $referencedIds = []): string
    {
        $searchService = app(WebSearchService::class);

        if (! $searchService->isConfigured()) {
            return '';
        }

        // Strip the search trigger phrase to get the actual query
        // \b before "search" prevents matching "research"
        // Also strips "/search <query>" when slash command is mid-message (after incident tags)
        $query = preg_replace(
            '/(?:\/search\s+|\bsearch\s+(?:the\s+)?(?:web|internet|online)\s*(?:for\s+)?|look\s+up\s+|check\s+online\s*(?:for\s+)?|\bsearch\s+for\s+)/i',
            '',
            $message
        );

        // Build smart query (extracts incident topics from IDs in text or referencedIds)
        $searchQuery = $this->buildSmartSearchQuery(trim($query), $referencedIds);

        if (empty(trim($searchQuery))) {
            return '';
        }

        $incidentContext = $this->getIncidentSearchContext($referencedIds, $query);
        $results = $searchService->search($searchQuery, $incidentContext);

        if (! empty($results['error']) || empty($results['context'])) {
            return "[Web search was performed but found no relevant public results for this topic. "
                ."Tell the user you searched the web but found no external references, "
                ."then provide your best analysis using internal incident data.]";
        }

        return "## Web Search Results\n{$results['context']}\n---";
    }

    private function getSearchContext(string $query, array $referencedIds = []): string
    {
        $searchService = app(WebSearchService::class);

        if (! $searchService->isConfigured()) {
            return "\n\n[Web search is not configured. Set AI_SEARCH_GEMINI_API_KEY in .env to enable /search.]";
        }

        $searchQuery = $this->buildSmartSearchQuery($query, $referencedIds);

        $incidentContext = $this->getIncidentSearchContext($referencedIds, $query);
        $results = $searchService->search($searchQuery, $incidentContext);

        if (! empty($results['error']) || empty($results['context'])) {
            return "\n\n[Web search was performed but found no relevant public results. "
                ."Tell the user you searched the web but found no external references, "
                ."then provide your best analysis using internal incident data.]";
        }

        return "\n\n---\n## Web Search Results\n{$results['context']}\n---\n"
            ."Use the above web search results to supplement your internal incident data analysis. "
            ."Reference external sources using markdown links in your response.";
    }

    /**
     * When /search args contain incident IDs, extract the incident's topic
     * to build a meaningful search query instead of sending the ID externally.
     */
    private function buildSmartSearchQuery(string $query, array $referencedIds = []): string
    {
        // Collect all incident IDs: from text + from explicit references
        $textIds = [];
        preg_match_all('/\d{4}_(?:IN|IS)_\d{4}/', $query, $textIds);
        $allIds = array_unique(array_merge($textIds[0] ?? [], $referencedIds));

        if (empty($allIds)) {
            return $this->cleanSearchQuery($query);
        }

        // Load the incidents to extract their topics
        $incidents = Incident::whereIn('no', $allIds)
            ->with(['labels'])
            ->get();

        if ($incidents->isEmpty()) {
            return $this->cleanSearchQuery($query);
        }

        $topicParts = [];

        foreach ($incidents as $inc) {
            if ($inc->root_cause_category) {
                $topicParts = array_merge($topicParts, (array) $inc->root_cause_category);
            }
            if ($inc->title) {
                $safeTitleWords = $this->extractSafeTitleWords($inc->title);
                $topicParts = array_merge($topicParts, $safeTitleWords);
            }
            foreach ($inc->labels as $label) {
                $topicParts[] = $label->name;
            }
        }

        // Remove incident IDs and command artifacts from the original query
        $cleanQuery = $this->cleanSearchQuery($query);

        $topicStr = implode(' ', array_unique(array_filter($topicParts)));

        // If the user's query is vague (just meta-instructions), use only incident topics + suffix
        if ($this->isVagueQuery($cleanQuery)) {
            $suffix = 'root cause prevention best practices';
            if ($cleanQuery) {
                $distilled = $this->distillQuery($cleanQuery);
                if ($distilled) {
                    return "{$topicStr} {$distilled}";
                }
            }

            return $topicStr ?: $this->cleanSearchQuery($query);
        }

        // Specific query: combine user terms + topic keywords
        if ($cleanQuery && $topicStr) {
            return "{$cleanQuery} {$topicStr}";
        }

        return $cleanQuery ?: $topicStr ?: $this->cleanSearchQuery($query);
    }

    /**
     * Check if a query contains only vague meta-instructions, not actual search terms.
     */
    private function isVagueQuery(string $query): bool
    {
        if (empty(trim($query))) {
            return true;
        }

        // Strip all filler words and see if anything substantive remains
        $stripped = $this->distillQuery($query);

        return empty(trim($stripped));
    }

    /**
     * Remove filler phrases from a query, keeping only substantive search terms.
     */
    private function distillQuery(string $query): string
    {
        $fillerPatterns = [
            // Meta-instructions
            '/\b(?:try|please|can\s+you|could\s+you|I\s+want|I\s+need|let\s+me|show\s+me)\b/i',
            '/\b(?:analyze|analyse|cross\s+reference|investigate|tell\s+me\s+about)\b/i',
            '/\b(?:this|that|the|it|incident|issue)\b/i',
            '/\b(?:internet\s+data|web\s+data|online|with\s+external|external\s+data|external\s+references?)\b/i',
            '/\b(?:is\s+there\s+any|any\s+way|or\s+any\s+insight|any\s+insight|any\s+idea)\b/i',
            '/\b(?:prevents?\s+(?:this|it|recurrence)|how\s+to\s+prevent|ways?\s+to\s+prevent)\b/i',
            '/\b(?:and\s+cross\s+reference\b|and\s+compare\b|and\s+search\b)\b/i',
            // Connectors and leftover glue words
            '/\b(?:and|with|or|for|from|about|into|also|just|really|some|much|more|very)\b/i',
            // Residual punctuation/junk
            '/\?+/',
        ];

        $clean = $query;
        foreach ($fillerPatterns as $pattern) {
            $clean = preg_replace($pattern, '', $clean);
        }
        $clean = preg_replace('/\s+/', ' ', $clean);

        return trim($clean);
    }

    /**
     * Build incident context summary for the gateway search prompt.
     */
    private function getIncidentSearchContext(array $referencedIds, string $query): array
    {
        $textIds = [];
        preg_match_all('/\d{4}_(?:IN|IS)_\d{4}/', $query, $textIds);
        $allIds = array_unique(array_merge($textIds[0] ?? [], $referencedIds));

        if (empty($allIds)) {
            return [];
        }

        $incidents = Incident::whereIn('no', $allIds)->with(['labels'])->get();
        if ($incidents->isEmpty()) {
            return [];
        }

        $context = [];
        foreach ($incidents as $inc) {
            $context[] = [
                'root_cause_categories' => $inc->root_cause_category ? (array) $inc->root_cause_category : [],
                'safe_title_words' => $this->extractSafeTitleWords($inc->title ?? ''),
                'labels' => $inc->labels->pluck('name')->toArray(),
            ];
        }

        return $context;
    }

    /**
     * Extract safe, generic words from an incident title for search queries.
     * Strips anything that could be confidential (proper nouns, internal codes, etc.)
     */
    private function extractSafeTitleWords(string $title): array
    {
        // Common stop words to skip
        $stopWords = ['the', 'a', 'an', 'of', 'in', 'on', 'at', 'to', 'for', 'and', 'or', 'is', 'was', 'by', 'with', 'from', 'due', 'not'];

        $words = preg_split('/[\s\-_:;,]+/', $title);
        $safe = [];

        foreach (array_slice($words, 0, 8) as $word) {
            $word = trim($word);
            if (strlen($word) < 3) {
                continue;
            }
            if (in_array(strtolower($word), $stopWords)) {
                continue;
            }
            // Skip anything that looks like a code, ID, or internal reference
            if (preg_match('/^[\d_]+$/', $word)) {
                continue;
            }
            // Skip words with mixed case patterns typical of internal codes (e.g., "Kafka", "API" is fine, "SVC_PROD" is not)
            if (preg_match('/^[A-Z]{2,}[_-]/', $word)) {
                continue;
            }
            $safe[] = $word;
        }

        return array_slice($safe, 0, 4);
    }

    /**
     * Clean a search query by removing incident IDs, brackets, and command artifacts.
     */
    private function cleanSearchQuery(string $query): string
    {
        // Remove incident IDs
        $clean = preg_replace('/\d{4}_(?:IN|IS)_\d{4}/', '', $query);
        // Remove brackets
        $clean = preg_replace('/[\[\]]/', '', $clean);
        // Remove slash commands and leading/trailing slashes
        $clean = preg_replace('~/\w+\b~', '', $clean);
        // Collapse whitespace
        $clean = preg_replace('/\s+/', ' ', $clean);

        return trim($clean);
    }

    public function getDataFreshness(): array
    {
        $statsCached = Cache::get('chat_quick_stats_v2');
        $incidentsCached = Cache::get('chat_recent_incidents_v2');

        return [
            'stats_cached' => $statsCached !== null,
            'incidents_cached' => $incidentsCached !== null,
            'cache_ttl' => 300,
        ];
    }

    public function clearDataCache(): void
    {
        Cache::forget('chat_quick_stats_v2');
        Cache::forget('chat_recent_incidents_v2');
    }

    private function getExecutiveSummaryContext(): string
    {
        $thisMonth = now()->startOfMonth();
        $lastMonth = now()->subMonth()->startOfMonth();
        $excludeQ = fn ($q) => $q->whereNull('fund_status')->orWhereNotIn('fund_status', FundStatus::EXCLUDED_FROM_COUNTS);

        $thisMonthCount = Incident::where('classification', 'Incident')->whereBetween('incident_date', [$thisMonth, now()])->where($excludeQ)->count();
        $lastMonthCount = Incident::where('classification', 'Incident')->whereBetween('incident_date', [$lastMonth, $thisMonth])->where($excludeQ)->count();
        $change = $lastMonthCount > 0 ? round((($thisMonthCount - $lastMonthCount) / $lastMonthCount) * 100, 1) : ($thisMonthCount > 0 ? 100 : 0);

        $thisMonthLoss = Incident::whereBetween('incident_date', [$thisMonth, now()])->where($excludeQ)->sum('fund_loss');
        $lastMonthLoss = Incident::whereBetween('incident_date', [$lastMonth, $thisMonth])->where($excludeQ)->sum('fund_loss');
        $lossChange = $lastMonthLoss > 0 ? round((($thisMonthLoss - $lastMonthLoss) / $lastMonthLoss) * 100, 1) : 0;

        $openP1P2 = Incident::where('classification', 'Incident')
            ->whereIn('severity', ['P1', 'P2'])
            ->whereNotIn('incident_status', ['Completed'])
            ->where($excludeQ)
            ->with(['pic', 'labels'])
            ->latest('incident_date')
            ->get();

        $overdueActions = \App\Models\ActionImprovement::where('status', 'pending')
            ->where('due_date', '<', now())
            ->count();

        $topIncidents = Incident::where('classification', 'Incident')
            ->whereBetween('incident_date', [$thisMonth, now()])
            ->where($excludeQ)
            ->whereIn('severity', Severity::METRIC_ELIGIBLE)
            ->with(['pic'])
            ->orderByRaw("FIELD(severity, 'P1','P2','P3','P4')")
            ->take(3)
            ->get();

        $lines = [
            "## Executive Summary Enrichment",
            "This month: {$thisMonthCount} incidents (vs {$lastMonthCount} last month → " . ($change >= 0 ? '+' : '') . "{$change}%)",
            "This month fund loss: Rp " . number_format($thisMonthLoss, 0, ',', '.') . " (vs last month Rp " . number_format($lastMonthLoss, 0, ',', '.') . " → " . ($lossChange >= 0 ? '+' : '') . "{$lossChange}%)",
            "Open P1/P2 incidents: " . $openP1P2->count(),
            "Overdue action improvements: {$overdueActions}",
        ];

        if ($openP1P2->isNotEmpty()) {
            $lines[] = "Urgent P1/P2 incidents:\n" . $openP1P2->map(fn ($i) => "- [{$i->no}](/admin/incidents/{$i->id}) {$i->title} | {$i->severity} | {$i->incident_status} | PIC: " . ($i->pic?->name ?? 'Unassigned'))->implode("\n");
        }

        if ($topIncidents->isNotEmpty()) {
            $lines[] = "Top incidents this month:\n" . $topIncidents->map(fn ($i) => "- [{$i->no}](/admin/incidents/{$i->id}) {$i->title} | {$i->severity} | PIC: " . ($i->pic?->name ?? 'Unassigned'))->implode("\n");
        }

        return implode("\n", $lines);
    }

    private function getSummaryContext(string $args): string
    {
        return $this->getExecutiveSummaryContext();
    }

    private function getCompareContext(string $args): string
    {
        $excludeQ = fn ($q) => $q->whereNull('fund_status')->orWhereNotIn('fund_status', FundStatus::EXCLUDED_FROM_COUNTS);
        $year = now()->year;

        $monthly = Incident::where($excludeQ)
            ->whereYear('incident_date', $year)
            ->selectRaw("MONTH(incident_date) as m, COUNT(*) as cnt, SUM(fund_loss) as loss, AVG(mttr) as avg_mttr")
            ->groupByRaw('MONTH(incident_date)')
            ->orderBy('m')
            ->get();

        $sevMonthly = Incident::where($excludeQ)
            ->whereYear('incident_date', $year)
            ->whereIn('severity', Severity::METRIC_ELIGIBLE)
            ->selectRaw("MONTH(incident_date) as m, severity, COUNT(*) as cnt")
            ->groupByRaw('MONTH(incident_date), severity')
            ->orderBy('m')
            ->get();

        $lines = ["## Monthly Comparison Data ({$year})"];
        foreach ($monthly as $row) {
            $sevs = $sevMonthly->where('m', $row->m)->map(fn ($s) => "{$s->severity}={$s->cnt}")->implode(', ');
            $lines[] = "Month {$row->m}: {$row->cnt} incidents | Fund Loss: Rp " . number_format($row->loss ?? 0, 0, ',', '.') . " | Avg MTTR: " . number_format($row->avg_mttr ?? 0, 0) . " min | Severity: {$sevs}";
        }

        return implode("\n", $lines);
    }

    private function getRiskContext(): string
    {
        $excludeQ = fn ($q) => $q->whereNull('fund_status')->orWhereNotIn('fund_status', FundStatus::EXCLUDED_FROM_COUNTS);

        $openP1P2 = Incident::whereIn('severity', ['P1', 'P2'])
            ->whereNotIn('incident_status', ['Completed'])
            ->where($excludeQ)
            ->with(['pic', 'actionImprovements'])
            ->latest('incident_date')
            ->get();

        $topFundLoss = Incident::where('fund_loss', '>', 0)
            ->where($excludeQ)
            ->whereYear('incident_date', now()->year)
            ->with('pic')
            ->orderByDesc('fund_loss')
            ->take(5)
            ->get();

        $overdueActions = \App\Models\ActionImprovement::where('status', 'pending')
            ->where('due_date', '<', now())
            ->with('incident')
            ->get();

        $lines = ["## Current Risk Overview"];

        if ($openP1P2->isNotEmpty()) {
            $lines[] = "### Open P1/P2 Incidents ({$openP1P2->count()})\n" . $openP1P2->map(fn ($i) => "- [{$i->no}](/admin/incidents/{$i->id}) {$i->title} | {$i->severity} | {$i->incident_status} | PIC: " . ($i->pic?->name ?? 'Unassigned') . ($i->fund_loss > 0 ? " | Loss: Rp " . number_format($i->fund_loss, 0, ',', '.') : ''))->implode("\n");
        } else {
            $lines[] = "### No open P1/P2 incidents. Well done!";
        }

        if ($topFundLoss->isNotEmpty()) {
            $lines[] = "### Top Fund Losses This Year\n" . $topFundLoss->map(fn ($i) => "- [{$i->no}](/admin/incidents/{$i->id}) Rp " . number_format($i->fund_loss, 0, ',', '.') . " | {$i->title} | PIC: " . ($i->pic?->name ?? 'Unassigned'))->implode("\n");
        }

        if ($overdueActions->isNotEmpty()) {
            $lines[] = "### Overdue Actions ({$overdueActions->count()})\n" . $overdueActions->map(fn ($a) => "- [{$a->incident?->no}](/admin/incidents/{$a->incident?->id}) {$a->title} (was due: {$a->due_date})")->implode("\n");
        }

        return implode("\n", $lines);
    }
}
