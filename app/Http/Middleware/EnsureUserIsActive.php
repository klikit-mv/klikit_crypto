<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->isActive()) {
            if ($request->expectsJson()) {
                $user->currentAccessToken()?->delete();

                return response()->json(['message' => 'Your account is suspended.'], 403);
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['email' => 'Your account is suspended.']);
        }

        return $next($request);
    }
}
