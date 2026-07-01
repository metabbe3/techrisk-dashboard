<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Route authorization failures through the global exception handler so they
     * receive the unified API envelope. The default FormRequest throws an
     * HttpResponseException that bypasses the AuthorizationException renderer.
     */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Unauthorized.');
    }
}
