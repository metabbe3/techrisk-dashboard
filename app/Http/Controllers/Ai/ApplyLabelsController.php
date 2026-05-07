<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\Label;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplyLabelsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'matched' => 'array',
            'matched.*' => 'string',
            'new_labels' => 'array',
            'new_labels.*' => 'string|max:50',
        ]);

        $labelIds = [];
        $matchedIds = collect();

        if (! empty($validated['matched'])) {
            $matchedIds = Label::whereIn('name', $validated['matched'])->pluck('id', 'name');
            $labelIds = $matchedIds->values()->all();
        }

        foreach ($validated['new_labels'] ?? [] as $name) {
            if ($matchedIds->has($name)) {
                continue;
            }
            $labelIds[] = Label::firstOrCreate(['name' => $name])->id;
        }

        return response()->json([
            'success' => true,
            'label_ids' => $labelIds,
        ]);
    }
}
