<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSesInboundEmail;
use App\Services\SesObjectStore;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SNS webhook for SES email receiving: confirms the subscription
 * handshake, then queues processing for each received-email notification.
 */
class SesInboundController extends Controller
{
    public function __invoke(Request $request, SesObjectStore $store): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || ! isset($payload['Type'])) {
            abort(400);
        }

        $this->verifySignature($payload);

        if ($payload['Type'] === 'SubscriptionConfirmation') {
            Http::get((string) $payload['SubscribeURL']);
            Log::info('SES inbound SNS subscription confirmed.');

            return response()->json(['status' => 'confirmed']);
        }

        if ($payload['Type'] !== 'Notification') {
            return response()->json(['status' => 'ignored']);
        }

        $message = json_decode((string) ($payload['Message'] ?? ''), true);

        if (! is_array($message) || ($message['notificationType'] ?? null) !== 'Received') {
            return response()->json(['status' => 'ignored']);
        }

        $action = $message['receipt']['action'] ?? [];

        if (($action['type'] ?? null) !== 'S3') {
            return response()->json(['status' => 'ignored']);
        }

        $bucket = (string) $action['bucketName'];
        $key = (string) $action['objectKey'];

        // SES scanned it on the way in — spam and viruses never reach a user.
        foreach (['spamVerdict', 'virusVerdict'] as $verdict) {
            if (strtoupper((string) ($message['receipt'][$verdict]['status'] ?? 'PASS')) === 'FAIL') {
                $store->delete($bucket, $key);
                Log::info("SES inbound rejected by {$verdict}.", ['key' => $key]);

                return response()->json(['status' => 'rejected']);
            }
        }

        ProcessSesInboundEmail::dispatch(
            bucket: $bucket,
            key: $key,
            recipients: array_map(strval(...), $message['receipt']['recipients'] ?? []),
            messageId: (string) ($message['mail']['messageId'] ?? $key),
        );

        return response()->json(['status' => 'queued']);
    }

    /** @param array<string, mixed> $payload */
    private function verifySignature(array $payload): void
    {
        if (! config('services.ses_inbound.verify_signature')) {
            return;
        }

        try {
            (new MessageValidator)->validate(new Message($payload));
        } catch (Throwable $e) {
            report($e);
            abort(403, 'Invalid SNS signature.');
        }
    }
}
