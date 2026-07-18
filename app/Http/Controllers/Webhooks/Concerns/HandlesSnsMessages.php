<?php

namespace App\Http\Controllers\Webhooks\Concerns;

use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared SNS plumbing: signature verification and the one-time
 * subscription-confirmation handshake. Returns the decoded inner
 * message for Notification posts, or null when the request was
 * fully handled (confirmation) or is not a notification.
 */
trait HandlesSnsMessages
{
    /** @return array<string, mixed>|null */
    protected function snsMessage(Request $request): ?array
    {
        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || ! isset($payload['Type'])) {
            abort(400);
        }

        if (config('services.ses_inbound.verify_signature')) {
            try {
                (new MessageValidator)->validate(new Message($payload));
            } catch (Throwable $e) {
                report($e);
                abort(403, 'Invalid SNS signature.');
            }
        }

        if ($payload['Type'] === 'SubscriptionConfirmation') {
            Http::get((string) $payload['SubscribeURL']);
            Log::info('SNS subscription confirmed.', ['topic' => $payload['TopicArn'] ?? null]);

            return null;
        }

        if ($payload['Type'] !== 'Notification') {
            return null;
        }

        $message = json_decode((string) ($payload['Message'] ?? ''), true);

        return is_array($message) ? $message : null;
    }
}
