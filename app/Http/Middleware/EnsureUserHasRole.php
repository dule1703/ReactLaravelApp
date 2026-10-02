<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware(['auth', 'role:admin']) or 'role:admin,client'.
 * Put `auth` first so guests are redirected to the login page instead of getting a 403.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = array_map(fn (string $role) => UserRole::from($role), $roles);

        abort_unless(
            $request->user() && in_array($request->user()->role, $allowed, true),
            403,
            __('This action is unauthorized.'),
        );

        return $next($request);
    }
}
