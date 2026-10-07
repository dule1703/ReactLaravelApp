<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser hardening headers on every response (6.3). Deliberately NOT here: Content-Security-Policy
 * (the Vite/Inertia pages need a tuned policy) and Strict-Transport-Security (a wrong HSTS cannot be
 * taken back quickly); both are decisions for a later phase.
 *
 *  - nosniff: the browser keeps the declared type (an uploaded image is never run as a script);
 *  - SAMEORIGIN: the application may be framed only by itself (the PDF opens in its own tab, nothing frames it);
 *  - strict-origin-when-cross-origin: a link to another site gets the origin, never the path (offer numbers);
 *  - Permissions-Policy: the app uses no camera, microphone or location.
 */
class SecurityHeaders
{
    public const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
