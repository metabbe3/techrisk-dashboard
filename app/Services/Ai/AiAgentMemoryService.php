<?php

namespace App\Services\Ai;

use App\Enums\AiAgentMemoryKind;
use App\Models\AiAgent;
use App\Models\AiAgentMemory;
use App\Models\AiAgentRun;
use Illuminate\Support\Str;

/**
 * The shared agent memory: one source of truth written by every agent run
 * (via an output-section convention — no tool calls) and read back into
 * prompts. Also renders the agent organization tree.
 */
class AiAgentMemoryService
{
    /**
     * Appended to the system prompt when include_memory is on. Keeps the
     * text-only safety ceiling: the agent writes memory as plain bullets in
     * its output; this service parses them out.
     */
    public const PROTOCOL = <<<'TXT'
    ## Memory protocol
    After your main output, end with a section titled "## Memory" (or "## Memory updates") containing up to 3 bullets for the shared agent memory. Each bullet must be exactly one of:
    - LESSON: one durable lesson this run learned
    - OUTCOME: one sentence on what this run concluded or produced
    - NOTE: one durable fact other agents should remember
    Only include bullets genuinely worth remembering; omit the whole section otherwise.
    TXT;

    /**
     * Parse the trailing "## Memory" section of an agent output into rows.
     * Tolerant: heading is case-insensitive and may be "Memory updates";
     * bullets may use - or *, bold markers, CRLF; the section ends at the
     * next heading; unparseable lines are dropped silently.
     *
     * @return array<int, array{kind: string, content: string}>
     */
    public function extractFromOutput(string $output): array
    {
        if (! preg_match('/^#{2,6}[ \t]*memory(?:[ \t]+updates)?[ \t]*\r?$/im', $output, $heading, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $section = substr($output, $heading[0][1] + strlen($heading[0][0]));

        if (preg_match('/^#{2,6}[ \t]+\S/m', $section, $end, PREG_OFFSET_CAPTURE)) {
            $section = substr($section, 0, $end[0][1]);
        }

        $rows = [];

        foreach (preg_split('/\R/', $section) ?: [] as $line) {
            if (preg_match('/^\s*[-*][ \t]*\**[ \t]*(lesson|outcome|note)[ \t]*\**[ \t]*[:\-–][ \t]*(.+)$/i', $line, $bullet)) {
                $content = trim($bullet[2], " \t*");

                if ($content !== '') {
                    $rows[] = ['kind' => ucfirst(strtolower($bullet[1])), 'content' => $content];
                }
            }
        }

        return $rows;
    }

    /**
     * Store human feedback on a completed run as a Feedback memory. The only
     * path that creates Feedback rows — the output parser only accepts
     * lesson/outcome/note, so agents can never forge their own feedback.
     */
    public function recordFeedback(AiAgentRun $run, string $feedback): AiAgentMemory
    {
        return AiAgentMemory::create([
            'agent_id' => $run->agent_id,
            'run_id' => $run->id,
            'kind' => AiAgentMemoryKind::Feedback->value,
            'content' => mb_substr(trim($feedback), 0, 1000),
        ]);
    }

    /**
     * The most recent memories as prompt lines, newest first. Empty string
     * when there is nothing yet (caller skips the block entirely).
     */
    public function recentForPrompt(int $limit): string
    {
        $rows = AiAgentMemory::with('agent')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();

        return $rows
            ->map(fn (AiAgentMemory $row): string => sprintf(
                '- %s [%s] %s: %s',
                $row->created_at?->format('Y-m-d') ?? '',
                $row->agent?->name ?? 'deleted agent',
                $row->kind->value,
                Str::limit($row->content, 300),
            ))
            ->implode("\n");
    }

    /**
     * The agent organization tree as indented text, with "(you)" marking the
     * running agent. Empty string when no agent reports to another (flat
     * organization — nothing to show). Orphan branches (parent missing or
     * disabled) render at root level so they never vanish silently.
     */
    public function orgTreeForPrompt(AiAgent $self): string
    {
        $agents = AiAgent::query()->where('enabled', true)->orderBy('name')->get();

        if ($agents->whereNotNull('reports_to_agent_id')->isEmpty()) {
            return '';
        }

        $byParent = $agents->groupBy('reports_to_agent_id');
        $ids = $agents->pluck('id')->flip();

        // Roots: no parent, or a parent outside the enabled set.
        $roots = $byParent->get(null, collect())
            ->concat($byParent->filter(fn ($children, $parent) => $parent !== null && ! $ids->has($parent))->flatten());

        $lines = [];
        $walk = function (AiAgent $agent, int $depth) use (&$walk, &$lines, $byParent, $self): void {
            // ponytail: visited-set guards corrupt-DB reporting cycles; renders once, moves on.
            static $visited = [];
            if (isset($visited[$agent->id])) {
                return;
            }
            $visited[$agent->id] = true;

            $lines[] = str_repeat('  ', $depth).'- '.$agent->name.($agent->id === $self->id ? ' (you)' : '');

            foreach ($byParent->get($agent->id, collect()) as $child) {
                $walk($child, $depth + 1);
            }
        };

        $roots->each(fn (AiAgent $agent) => $walk($agent, 0));

        return implode("\n", $lines);
    }
}
