<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ValidatePostSize runs inside the web group (after the session starts) instead of globally,
        // so a 413 can be turned into a flashed field error (see the exception handler below).
        $middleware->remove(ValidatePostSize::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            ValidatePostSize::class,
        ]);

        // Hardening headers on every response, also on /up and on error pages (6.3).
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A rejected JMBG or PIB must not be stored in the session as "old input" (passwords are in Laravel's default list).
        $exceptions->dontFlash(['jmbg', 'pib', 'client_pib', 'issuer_pib']);

        // 429 (6.3): JSON gets a Serbian message; a form (an Inertia visit or a POST/PATCH/PUT/DELETE)
        // goes back with a flashed error that FlashMessages shows; a plain GET (a PDF link opened in a new
        // tab) keeps the standard 429 page. Retry-After and the X-RateLimit headers stay in every case.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            $message = __('Too many requests. Try again shortly.');

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 429, $e->getHeaders());
            }

            if ($request->header('X-Inertia') || ! $request->isMethodSafe()) {
                return back()->with('error', $message)->withHeaders($e->getHeaders());
            }

            return null;
        });

        // A request body above post_max_size is rejected before validation (413). On the car model
        // form it becomes a message next to the image field instead of an error page.
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            if (! $request->is('admin/catalog/models', 'admin/catalog/models/*', 'admin/catalog/equipment', 'admin/catalog/equipment/*')) {
                return null;
            }

            $message = __('The image is larger than the allowed 2 MB.');

            return $request->expectsJson()
                ? response()->json(['message' => $message, 'errors' => ['image' => [$message]]], 422)
                : back()->withErrors(['image' => $message]);
        });
    })->create();
