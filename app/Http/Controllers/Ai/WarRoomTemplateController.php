<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\WarRoomTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarRoomTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $templates = WarRoomTemplate::where('user_id', $request->user()->id)
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json(['templates' => $templates]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'selected_agents' => 'required|array|min:1',
            'selected_agents.*' => 'string',
            'max_rounds' => 'integer|min:1|max:5',
            'model' => 'nullable|string|max:100',
            'moderator_model' => 'nullable|string|max:100',
            'enable_web_search' => 'boolean',
            'deep_analysis' => 'boolean',
            'user_instructions' => 'nullable|string|max:5000',
        ]);

        $template = WarRoomTemplate::create([
            ...$data,
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['template' => $template], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $template = WarRoomTemplate::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'selected_agents' => 'sometimes|array|min:1',
            'selected_agents.*' => 'string',
            'max_rounds' => 'integer|min:1|max:5',
            'model' => 'nullable|string|max:100',
            'moderator_model' => 'nullable|string|max:100',
            'enable_web_search' => 'boolean',
            'deep_analysis' => 'boolean',
            'user_instructions' => 'nullable|string|max:5000',
        ]);

        $template->update($data);

        return response()->json(['template' => $template->fresh()]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $template = WarRoomTemplate::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        $template->delete();

        return response()->json(['deleted' => true]);
    }
}
