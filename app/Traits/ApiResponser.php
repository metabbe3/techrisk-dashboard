<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponser
{
    protected function successResponse($data, $message = null, $code = 200): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'status' => 'Success',
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    protected function errorResponse($message, $code): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'status' => 'Error',
            'message' => $message,
            'data' => null,
        ], $code);
    }
}
