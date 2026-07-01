<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\ChatStreamService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatStreamController extends Controller
{
    public function __construct(
        private ChatStreamService $chatStreamService,
    ) {}

    public function __invoke(Request $request): StreamedResponse
    {
        return $this->chatStreamService->handle($request);
    }
}
