<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ValidatePostSize runs inside the web group (after the session starts) instead of globally,
        // so a 413 can be turned into a flashed field error (see the exception handler below).
        $middleware->remove(\Illuminate\Http\Middleware\ValidatePostSize::class);

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \Illuminate\Http\Middleware\ValidatePostSize::class,
        ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A rejected JMBG must not be stored in the session as "old input".
        $exceptions->dontFlash(['jmbg']);

        // A request body above post_max_size is rejected before validation (413). On the car model
        // form it becomes a message next to the image field instead of an error page.
        $exceptions->render(function (\Illuminate\Http\Exceptions\PostTooLargeException $e, \Illuminate\Http\Request $request) {
            if (! $request->is('admin/catalog/models', 'admin/catalog/models/*')) {
                return null;
            }

            $message = __('The image is larger than the allowed 2 MB.');

            return $request->expectsJson()
                ? response()->json(['message' => $message, 'errors' => ['image' => [$message]]], 422)
                : back()->withErrors(['image' => $message]);
        });
    })->create();
