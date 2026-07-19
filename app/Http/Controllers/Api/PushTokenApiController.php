<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Device push token registry for the companion app. The app re-registers
 * on every launch; the token string is the identity, so a device that
 * switches accounts simply moves its token to the new user.
 */
class PushTokenApiController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'in:ios'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        PushToken::updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $validated['platform'],
                'device_name' => $validated['device_name'],
            ],
        );

        return response()->json(null, 201);
    }
}
