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
        ]);

        // Register API audit logger for all API routes
        $middleware->api([
            \App\Http\Middleware\ApiAuditLogger::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
