<?php

namespace App\Http\Controllers\Ai;

use App\Models\Incident;
use App\Services\Markdown\IncidentMarkdownExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarRoomEstimateTokensController
{
    public function __construct(
        private IncidentMarkdownExporter $exporter,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'incident_ids' => 'required|array|min:1',
            'incident_ids.*' => 'integer|exists:incidents,id',
            'model' => 'nullable|string',
        ]);

        $incidents = Incident::whereIn('id', $request->input('incident_ids'))
            ->orderBy('incident_date', 'desc')
            ->get();

        $context = [];
        foreach ($incidents as $inc) {
            $context[] = "--- Incident: {$inc->no} ({$inc->severity}) ---";
            $context[] = $this->exporter->generateCompact($inc);
        }

        $contextText = implode("\n", $context);
        $estimatedTokens = intdiv(strlen($contextText), 4);

        $model = $request->input('model') ?? config('ai.war_room.default_model') ?? 'default';
        $limits = config('ai.war_room.model_limits', []);
        $inputLimit = $limits[$model]['input'] ?? config('ai.war_room.default_input_limit', 32000);

        $pct = round(($estimatedTokens / $inputLimit) * 100);
        $willCompress = $pct > 75;

        return response()->json([
            'estimated_tokens' => $estimatedTokens,
            'input_limit' => $inputLimit,
            'percentage' => min($pct, 100),
            'will_compress' => $willCompress,
            'model' => $model,
            'incident_count' => $incidents->count(),
        ]);
    }
}
