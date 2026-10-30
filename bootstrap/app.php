<?php

use App\Http\Middleware\EnsureFeature;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RequireIdempotencyKey;
use App\Http\Middleware\SecurityHeaders;
use App\Services\Trading\Exceptions\RiskViolation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware(['web', 'auth', 'active', 'can:admin.access'])
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'feature' => EnsureFeature::class,
            'idempotent' => RequireIdempotencyKey::class,
        ]);
        $middleware->append(SecurityHeaders::class);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (RiskViolation $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'rule' => $e->rule], 422);
            }

            return back()->withInput()->withErrors(['order' => $e->getMessage()]);
        });
    })->create();
