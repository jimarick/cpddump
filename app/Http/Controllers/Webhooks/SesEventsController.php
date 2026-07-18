<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Webhooks\Concerns\HandlesSnsMessages;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SES sending events (bounces + complaints). Hard bounces and spam
 * complaints suppress future sends to that address — mandatory hygiene
 * for keeping SES sending healthy, and basic respect either way.
 */
class SesEventsController extends Controller
{
    use HandlesSnsMessages;

    public function __invoke(Request $request): JsonResponse
    {
        $message = $this->snsMessage($request);

        if ($message === null) {
            return response()->json(['status' => 'ok']);
        }

        match ($message['notificationType'] ?? null) {
            'Bounce' => $this->handleBounce($message),
            'Complaint' => $this->handleComplaint($message),
            default => null,
        };

        return response()->json(['status' => 'ok']);
    }

    /** @param array<string, mixed> $message */
    private function handleBounce(array $message): void
    {
        if (($message['bounce']['bounceType'] ?? null) !== 'Permanent') {
            return; // Transient bounces (full mailbox etc.) resolve themselves.
        }

        foreach ($message['bounce']['bouncedRecipients'] ?? [] as $recipient) {
            $this->suppress((string) ($recipient['emailAddress'] ?? ''), 'bounce');
        }
    }

    /** @param array<string, mixed> $message */
    private function handleComplaint(array $message): void
    {
        foreach ($message['complaint']['complainedRecipients'] ?? [] as $recipient) {
            $this->suppress((string) ($recipient['emailAddress'] ?? ''), 'complaint');
        }
    }

    private function suppress(string $email, string $reason): void
    {
        if ($email === '') {
            return;
        }

        User::query()
            ->where('email', $email)
            ->whereNull('email_suppressed_at')
            ->update([
                'email_suppressed_at' => now(),
                'email_suppression_reason' => $reason,
            ]);

        Log::info('Email suppressed.', ['email' => $email, 'reason' => $reason]);
    }
}
