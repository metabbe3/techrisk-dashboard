<?php

namespace App\Http\Middleware;

use App\Services\Ai\AiTextService;
use Closure;
use Illuminate\Http\Request;

class EnsureAiAvailable
{
    public function __construct(
        private readonly AiTextService $aiService
    ) {}

    public function handle(Request $request, Closure $next, string ...$extraKeys)
    {
        if ($this->aiService->isAvailable()) {
            return $next($request);
        }

        $extras = [];
        foreach ($extraKeys as $key) {
            $extras[$key] = $key === 'matched' || $key === 'suggested' ? [] : null;
        }

        return response()->json([
            'code' => 503,
            'status' => 'Error',
            'message' => 'AI service is not configured. Run php artisan ai:setup or configure AI settings.',
            'data' => empty($extras) ? null : $extras,
        ], 503);
    }
}
