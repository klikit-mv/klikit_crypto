<?php

namespace App\Modules\Auth\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Http\Requests\RegisterRequest;
use App\Services\Access\AuditLogger;
use App\Services\Access\UserRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, UserRegistrationService $registration): JsonResponse
    {
        $user = $registration->register($request->validated());

        return response()->json([
            'user' => $this->profilePayload($user),
            'token' => $user->createToken($request->input('device_name', 'api'))->plainTextToken,
        ], 201);
    }

    public function login(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            $audit->log('auth.login_failed', $user, null, ['email' => $data['email']]);
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
        if (! $user->isActive()) {
            throw ValidationException::withMessages(['email' => 'Your account is suspended.']);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $audit->log('auth.login', $user, $user, ['channel' => 'api']);

        return response()->json([
            'user' => $this->profilePayload($user),
            'token' => $user->createToken($data['device_name'] ?? 'api')->plainTextToken,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function profile(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->profilePayload($request->user())]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'size:2'],
            'timezone' => ['sometimes', 'timezone:all'],
        ]);

        $user = $request->user();
        if (isset($data['name'])) {
            $user->update(['name' => $data['name']]);
        }
        $user->profile()->updateOrCreate([], array_intersect_key($data, array_flip(['phone', 'country', 'timezone'])));

        return response()->json(['user' => $this->profilePayload($user->fresh())]);
    }

    private function profilePayload(User $user): array
    {
        $user->loadMissing('profile', 'roles');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'roles' => $user->roles->pluck('name'),
            'profile' => $user->profile?->only(['phone', 'country', 'timezone', 'kyc_status']),
        ];
    }
}
