<?php

namespace App\Modules\Auth\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Requests\RegisterRequest;
use App\Services\Access\AuditLogger;
use App\Services\Access\UserRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function showLogin(): View
    {
        return view('authentication.login');
    }

    public function login(Request $request, AuditLogger $audit): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            $audit->log('auth.login_failed', null, null, ['email' => $credentials['email']]);
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $user = Auth::user();
        if (! $user->isActive()) {
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'Your account is suspended.']);
        }

        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        $audit->log('auth.login', $user, $user, ['channel' => 'web']);

        return redirect()->intended($user->can('admin.access') ? route('admin.dashboard') : route('dashboard'));
    }

    public function showRegister(): View
    {
        return view('authentication.register');
    }

    public function register(RegisterRequest $request, UserRegistrationService $registration): RedirectResponse
    {
        $user = $registration->register($request->validated());
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Welcome! Your paper trading account has been funded.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
