<?php

namespace App\Notifications\Channels;

use App\Models\PushToken;
use App\Models\User;
use App\Services\Apns\ApnsClient;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Delivers a notification's toApns() payload to every iOS device the
 * user has registered, and drops tokens APNs declares dead so the table
 * never accumulates uninstalled devices.
 */
class ApnsChannel
{
    public function __construct(private ApnsClient $client) {}

    public function send(User $notifiable, Notification $notification): void
    {
        /** @phpstan-ignore method.notFound */
        $payload = $notification->toApns($notifiable);

        $notifiable->pushTokens()
            ->where('platform', 'ios')
            ->get()
            ->each(function (PushToken $token) use ($payload) {
                $response = $this->client->push($token->token, $payload);

                if ($response->tokenGone()) {
                    $token->delete();

                    return;
                }

                if (! $response->delivered()) {
                    Log::warning('APNs push failed', [
                        'push_token_id' => $token->id,
                        'status' => $response->status,
                        'reason' => $response->reason,
                    ]);
                }
            });
    }
}
