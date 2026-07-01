<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponser
{
    /**
     * Unified success response.
     *
     * @param  mixed  $data
     */
    protected function successResponse($data, ?string $message = null, int $code = 200, ?array $meta = null): JsonResponse
    {
        return $this->respond($code, $message, $data, meta: $meta);
    }

    /**
     * Unified error response.
     */
    protected function errorResponse(?string $message, int $code): JsonResponse
    {
        return $this->respond($code, $message);
    }

    /**
     * Error response that still carries a payload (e.g. an id the client needs).
     */
    protected function errorResponseWithData(?string $message, int $code, mixed $data = null): JsonResponse
    {
        return $this->respond($code, $message, $data);
    }

    /**
     * Validation failure response (adds the optional `errors` key).
     *
     * @param  array<string, mixed>  $errors
     */
    protected function validationResponse(string $message, array $errors, int $code = 422): JsonResponse
    {
        return $this->respond($code, $message, null, $errors);
    }

    /**
     * Single source of the API response envelope:
     * { code, status, message, data } (+ optional errors, meta).
     *
     * @param  array<string, mixed>|null  $errors
     * @param  array<string, mixed>|null  $meta  pagination/extra metadata
     */
    private function respond(int $code, ?string $message, mixed $data = null, ?array $errors = null, ?array $meta = null): JsonResponse
    {
        $payload = [
            'code' => $code,
            'status' => $code < 400 ? 'Success' : 'Error',
            'message' => $message,
            'data' => $data,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $code);
    }
}
