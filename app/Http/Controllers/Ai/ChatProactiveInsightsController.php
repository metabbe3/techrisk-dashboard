<?php

namespace App\Http\Controllers\Ai;

use App\Models\ProactiveInsight;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatProactiveInsightsController
{
    public function index(Request $request): JsonResponse
    {
        $insights = ProactiveInsight::forUser($request->user()->id)
            ->unread()
            ->with('incident:id,no,title,severity')
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($insight) => [
                'id' => $insight->id,
                'type' => $insight->insight_type,
                'content' => $insight->content,
                'incident' => $insight->incident ? [
                    'no' => $insight->incident->no,
                    'title' => $insight->incident->title,
                    'severity' => $insight->incident->severity,
                ] : null,
                'created_at' => $insight->created_at?->toISOString(),
            ]);

        return response()->json([
            'success' => true,
            'insights' => $insights,
        ]);
    }

    public function markRead(string $id, Request $request): JsonResponse
    {
        $insight = ProactiveInsight::forUser($request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        $insight->update(['is_read' => true]);

        return response()->json(['success' => true]);
    }
}
