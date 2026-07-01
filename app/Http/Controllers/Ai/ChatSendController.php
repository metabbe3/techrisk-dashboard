<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\ChatSendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatSendController extends Controller
{
    public function __construct(
        private ChatSendService $chatSendService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->chatSendService->handle($request);
    }
}
