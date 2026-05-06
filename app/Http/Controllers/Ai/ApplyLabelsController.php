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

        foreach ($validated['matched'] ?? [] as $name) {
            $label = Label::where('name', $name)->first();
            if ($label) {
                $labelIds[] = $label->id;
            }
        }

        foreach ($validated['new_labels'] ?? [] as $name) {
            $label = Label::firstOrCreate(['name' => $name]);
            $labelIds[] = $label->id;
        }

        return response()->json([
            'success' => true,
            'label_ids' => $labelIds,
        ]);
    }
}
