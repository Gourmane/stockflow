<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Http\Middleware\EnsureUserIsActive;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->statefulApi();

        // API requests should not redirect to a login page
        $middleware->redirectGuestsTo(
            fn (Request $request) =>
                $request->is('api/*') ? null : '/login'
        );

        $middleware->alias([
        'active' => EnsureUserIsActive::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {

        // Always render API exceptions as JSON
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) =>
                $request->is('api/*') || $request->expectsJson()
        );

    })
    ->create();