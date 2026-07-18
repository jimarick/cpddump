<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * Token auth for the companion app: exchange credentials (plus a TOTP or
 * recovery code when two-factor is enabled) for a Sanctum bearer token.
 */
class AuthApiController extends Controller
{
    public function token(Request $request, TwoFactorAuthenticationProvider $twoFactor): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:10'],
            'recovery_code' => ['nullable', 'string', 'max:64'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $this->verifySecondFactor($user, $twoFactor, $validated);
        }

        $token = $user->createToken($validated['device_name']);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => $this->userPayload($user),
        ], 201);
    }

    public function revoke(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }

    /** Who am I, plus everything the app shell needs on launch. */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'onboarded' => $user->hasOnboarded(),
            'profession' => $user->profession?->only(['id', 'slug', 'name']),
            'dump_address' => $user->inboundEmailAddress(),
            'period' => $user->currentAppraisalPeriod()?->only(['id', 'label', 'starts_on', 'ends_on']),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function verifySecondFactor(User $user, TwoFactorAuthenticationProvider $twoFactor, array $validated): void
    {
        if (filled($validated['code'] ?? null)) {
            if (! $twoFactor->verify(decrypt($user->two_factor_secret), $validated['code'])) {
                throw ValidationException::withMessages(['code' => __('The provided two factor authentication code was invalid.')]);
            }

            return;
        }

        if (filled($validated['recovery_code'] ?? null)) {
            $match = collect($user->recoveryCodes())->first(
                fn ($code) => hash_equals($code, $validated['recovery_code'])
            );

            if (! $match) {
                throw ValidationException::withMessages(['recovery_code' => __('The provided two factor recovery code was invalid.')]);
            }

            $user->replaceRecoveryCode($match);

            return;
        }

        throw ValidationException::withMessages([
            'code' => __('Two factor authentication code required.'),
        ]);
    }
}
