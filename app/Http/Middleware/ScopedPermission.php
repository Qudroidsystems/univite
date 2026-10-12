<?php

namespace App\Http\Middleware;

use App\Services\Access\ScopedAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Like the "permission" middleware, but also lets in someone who holds one of the permissions only for a faculty,
 * department or unit. The page itself then shows only what is in their scope. Usage: ->middleware('scoped:View students')
 */
class ScopedPermission
{
    public function __construct(private ScopedAccess $access)
    {
    }

    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        abort_unless($this->access->any($request->user(), ...explode('|', $permissions)), 403);

        return $next($request);
    }
}
