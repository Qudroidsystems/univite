<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the existing controllers (which "redirect back with a message") serve pages that save without reloading.
 * For requests sent by the portal's own script (X-Requested-With: XMLHttpRequest) a redirect becomes JSON:
 * the flash message, validation errors and where to go next. Ordinary browser requests are untouched.
 *
 * It also protects against a double save: a retry sent with the same X-Idempotency-Key gets the first answer
 * back instead of running the action again (a timed-out request may well have succeeded).
 */
class AjaxResponses
{
    private const FLASH_KEYS = ['success', 'error', 'warning', 'status', 'generated_password'];

    public function handle(Request $request, Closure $next): Response
    {
        // Only saves (POST, PUT, PATCH, DELETE) are answered with JSON. Page loads keep their normal redirects.
        if (! $request->ajax() || in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $key = $request->header('X-Idempotency-Key');
        if (! $key || ! preg_match('/^[A-Za-z0-9\-]{8,64}$/', $key)) {
            return $this->convert($request, $next($request));
        }

        $who = $request->user()?->getAuthIdentifier() ?? ($request->hasSession() ? $request->session()->getId() : $request->ip());
        $cacheKey = 'idem:' . $who . ':' . $key;

        try {
            return Cache::lock($cacheKey . ':lock', 40)->block(35, function () use ($request, $next, $cacheKey) {
                if ($stored = Cache::get($cacheKey)) {
                    return response()->json($stored['body'], $stored['status'])->header('X-Idempotent-Replay', '1');
                }

                $response = $this->convert($request, $next($request));

                if ($response->getStatusCode() < 500 && $response->headers->get('Content-Type') && str_contains($response->headers->get('Content-Type'), 'json')) {
                    Cache::put($cacheKey, ['body' => json_decode($response->getContent(), true), 'status' => $response->getStatusCode()], now()->addMinutes(3));
                }

                return $response;
            });
        } catch (\Throwable $e) {
            if ($e instanceof \Illuminate\Contracts\Cache\LockTimeoutException) {
                return response()->json(['message' => 'The first attempt is still being processed. Please wait a moment and check before trying again.'], 409);
            }
            throw $e;
        }
    }

    private function convert(Request $request, Response $response): Response
    {
        if (! $request->ajax() || ! $response instanceof RedirectResponse || ! $request->hasSession()) {
            return $response;
        }

        $session = $request->session();
        $errors = $session->get('errors');

        if ($errors instanceof ViewErrorBag && $errors->any()) {
            $session->forget(['errors', '_old_input']);

            return response()->json([
                'message' => $errors->getBag('default')->first() ?: 'Please check the form.',
                'errors'  => $errors->getBag('default')->messages(),
            ], 422);
        }

        $flash = [];
        foreach (self::FLASH_KEYS as $k) {
            if ($session->has($k)) {
                $flash[$k] = $session->pull($k);
            }
        }
        $session->forget('_old_input');

        return response()->json([
            'ok'       => ! isset($flash['error']),
            'message'  => $flash['success'] ?? $flash['error'] ?? $flash['warning'] ?? $flash['status'] ?? null,
            'flash'    => $flash,
            'redirect' => $response->getTargetUrl(),
        ]);
    }
}
