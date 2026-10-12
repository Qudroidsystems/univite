<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The mobile API always answers in JSON, and a disabled account's tokens stop working at once. */
class ApiJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        $user = $request->user('sanctum');
        if ($user?->is_disabled) {
            $user->tokens()->delete();
            $user->deviceTokens()->delete();

            return response()->json(['message' => 'This account has been disabled. Please contact the administrator.'], 403);
        }

        return $next($request);
    }
}
