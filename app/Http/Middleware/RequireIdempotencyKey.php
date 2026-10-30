<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency for financial and trading operations. Clients send an `Idempotency-Key`
 * header; a retried request with the same key and body replays the stored response
 * instead of executing twice. Same key with a different body is rejected.
 */
class RequireIdempotencyKey
{
    private const TTL_SECONDS = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $key = (string) $request->header('Idempotency-Key');
        if (! preg_match('/^[A-Za-z0-9_\-:.]{8,100}$/', $key)) {
            return response()->json(['message' => 'A valid Idempotency-Key header (8-100 characters) is required.'], 400);
        }

        $scope = 'idem:'.($request->user()?->id ?? $request->ip()).':'.$request->method().':'.$request->path().':'.$key;
        $fingerprint = hash('sha256', json_encode($request->all()));

        $lock = Cache::lock($scope.':lock', 30);
        if (! $lock->get()) {
            return response()->json(['message' => 'A request with this Idempotency-Key is already in progress.'], 409);
        }

        try {
            if ($stored = Cache::get($scope)) {
                if ($stored['fingerprint'] !== $fingerprint) {
                    return response()->json(['message' => 'Idempotency-Key was already used with a different request.'], 422);
                }

                return response($stored['body'], $stored['status'], $stored['headers'] + ['Idempotent-Replayed' => 'true']);
            }

            $response = $next($request);

            if ($response->getStatusCode() < 500) {
                Cache::put($scope, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'body' => $response->getContent(),
                    'headers' => ['Content-Type' => $response->headers->get('Content-Type')],
                ], self::TTL_SECONDS);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }
}
