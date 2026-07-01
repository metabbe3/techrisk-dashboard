<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(\App\Http\Middleware\TrustProxies::class);
        $middleware->alias([
            'check.api.access' => \App\Http\Middleware\CheckApiAccess::class,
            'check.api.token.access' => \App\Http\Middleware\CheckApiTokenAccess::class,
            'ai.available' => \App\Http\Middleware\EnsureAiAvailable::class,
        ]);

        // Register API audit logger for all API routes
        $middleware->api([
            \App\Http\Middleware\ApiAuditLogger::class,
        ]);

        // Run the audit logger BEFORE SubstituteBindings/Authenticate so it still
        // captures requests that throw before reaching the controller (404 model
        // binding, 401 auth, etc.). Listed first; the rest are Laravel's defaults.
        $middleware->priority([
            \App\Http\Middleware\ApiAuditLogger::class,
            \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
            \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);

        $middleware->redirectGuestsTo(function ($request) {
            if ($request->expectsJson() || str_starts_with($request->path(), 'api/')) {
                return null;
            }

            return route('filament.admin.auth.login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // ponytail: one shape for the whole API surface — success and every error path.
        // Defined once here and mirrored by the ApiResponser trait used in controllers.
        $isApiRequest = fn ($request): bool => $request->expectsJson() || str_starts_with($request->path(), 'api/');

        $apiResponse = static function (int $code, string $message, mixed $data = null, ?array $errors = null) {
            $payload = [
                'code' => $code,
                'status' => $code < 400 ? 'Success' : 'Error',
                'message' => $message,
                'data' => $data,
            ];

            if ($errors !== null) {
                $payload['errors'] = $errors;
            }

            return response()->json($payload, $code);
        };

        $exceptions->renderable(function (\Illuminate\Validation\ValidationException $e, $request) use ($apiResponse, $isApiRequest) {
            if ($isApiRequest($request)) {
                return $apiResponse(422, 'The given data was invalid.', null, $e->errors());
            }
        });

        $exceptions->renderable(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, $request) use ($apiResponse, $isApiRequest) {
            if ($isApiRequest($request)) {
                return $apiResponse(404, 'Resource not found.');
            }
        });

        $exceptions->renderable(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, $request) use ($apiResponse, $isApiRequest) {
            if ($isApiRequest($request)) {
                return $apiResponse(404, 'Resource not found.');
            }
        });

        $exceptions->renderable(function (\Illuminate\Auth\AuthenticationException $e, $request) use ($apiResponse, $isApiRequest) {
            if ($isApiRequest($request)) {
                return $apiResponse(401, 'Unauthenticated.');
            }
        });

        $exceptions->renderable(function (\Illuminate\Auth\Access\AuthorizationException $e, $request) use ($apiResponse, $isApiRequest) {
            if ($isApiRequest($request)) {
                return $apiResponse(403, 'Unauthorized.');
            }
        });

        // Registered before the generic HttpException so it takes precedence (it subclasses HttpException).
        $exceptions->renderable(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e, $request) use ($apiResponse, $isApiRequest) {
            if ($isApiRequest($request)) {
                return $apiResponse(429, 'Too many requests. Please try again later.');
            }
        });

        $exceptions->renderable(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, $request) use ($apiResponse, $isApiRequest) {
            if ($isApiRequest($request)) {
                return $apiResponse($e->getStatusCode(), $e->getMessage() ?: 'Error.');
            }
        });

        // Fallback: never leak the raw exception message to the client. Full detail stays in the log.
        $exceptions->renderable(function (\Throwable $e, $request) use ($apiResponse, $isApiRequest) {
            if ($isApiRequest($request)) {
                \Illuminate\Support\Facades\Log::error('Unhandled API exception: '.$e->getMessage(), [
                    'exception' => get_class($e),
                    'file' => $e->getFile().':'.$e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return $apiResponse(500, 'Internal server error.');
            }
        });
    })->create();
