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

        $middleware->redirectGuestsTo(function ($request) {
            if ($request->expectsJson() || str_starts_with($request->path(), 'api/')) {
                return null;
            }

            return route('filament.admin.auth.login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->renderable(function (\Illuminate\Validation\ValidationException $e, $request) {
            if ($request->expectsJson() || str_starts_with($request->path(), 'api/')) {
                return response()->json([
                    'code' => 422,
                    'status' => 'Error',
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                    'data' => null,
                ], 422);
            }
        });

        $exceptions->renderable(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, $request) {
            if ($request->expectsJson() || str_starts_with($request->path(), 'api/')) {
                return response()->json([
                    'code' => 404,
                    'status' => 'Error',
                    'message' => 'Resource not found.',
                    'data' => null,
                ], 404);
            }
        });

        $exceptions->renderable(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, $request) {
            if ($request->expectsJson() || str_starts_with($request->path(), 'api/')) {
                return response()->json([
                    'code' => 404,
                    'status' => 'Error',
                    'message' => 'Resource not found.',
                    'data' => null,
                ], 404);
            }
        });

        $exceptions->renderable(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->expectsJson() || str_starts_with($request->path(), 'api/')) {
                return response()->json([
                    'code' => 401,
                    'status' => 'Error',
                    'message' => 'Unauthenticated.',
                    'data' => null,
                ], 401);
            }
        });

        $exceptions->renderable(function (\Illuminate\Auth\Access\AuthorizationException $e, $request) {
            if ($request->expectsJson() || str_starts_with($request->path(), 'api/')) {
                return response()->json([
                    'code' => 403,
                    'status' => 'Error',
                    'message' => 'Unauthorized.',
                    'data' => null,
                ], 403);
            }
        });

        $exceptions->renderable(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, $request) {
            if ($request->expectsJson() || str_starts_with($request->path(), 'api/')) {
                return response()->json([
                    'code' => $e->getStatusCode(),
                    'status' => 'Error',
                    'message' => $e->getMessage() ?: 'Error.',
                    'data' => null,
                ], $e->getStatusCode());
            }
        });

        $exceptions->renderable(function (\Throwable $e, $request) {
            if ($request->expectsJson() || str_starts_with($request->path(), 'api/')) {
                \Illuminate\Support\Facades\Log::error('Unhandled API exception: '.$e->getMessage(), [
                    'exception' => get_class($e),
                    'file' => $e->getFile().':'.$e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return response()->json([
                    'code' => 500,
                    'status' => 'Error',
                    'message' => config('app.debug') ? $e->getMessage() : 'Internal server error.',
                    'data' => null,
                ], 500);
            }
        });
    })->create();
