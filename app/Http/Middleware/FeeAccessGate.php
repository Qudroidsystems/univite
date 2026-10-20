<?php

namespace App\Http\Middleware;

use App\Services\Fees\FeeAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the school bars students who have not paid enough of the session's fees from the whole portal and app, a
 * student below the share set is sent to the page that says so (the app gets a 403 with the reason). Their fees,
 * payments, receipts, loans and notifications stay open so they can pay.
 */
class FeeAccessGate
{
    private const OPEN = [
        'my.fees*', 'my.receipt', 'my.loans*', 'my.access-locked', 'payments.*', 'logout', 'login*', 'password.*', 'notifications.*', 'dashboard', 'home',
        'api.logout', 'api.me', 'api.dashboard', 'api.fees*', 'api.payments.*', 'api.devices.*', 'api.loans*', 'api.notifications*', 'api.ping', 'api.login', 'api.me.password',
    ];

    public function __construct(private FeeAccess $access)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! FeeAccess::areaOn('portal') || ! ($user = $request->user()) || ! ($student = $user->student) || $user->can('View dashboard')) {
            return $next($request);
        }
        $route = $request->route();
        if (! $route || $request->routeIs(...self::OPEN)) {
            return $next($request);
        }
        $reason = $this->access->blocker($student, 'portal');
        if ($reason === null) {
            return $next($request);
        }
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $reason, 'code' => 'fees_access', 'data' => $this->access->standing($student)->toArray()], 403);
        }

        return redirect()->route('my.access-locked');
    }
}
