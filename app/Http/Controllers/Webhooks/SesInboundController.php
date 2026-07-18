<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Webhooks\Concerns\HandlesSnsMessages;
use App\Jobs\ProcessSesInboundEmail;
use App\Services\SesObjectStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SNS webhook for SES email receiving: confirms the subscription
 * handshake, then queues processing for each received-email notification.
 */
class SesInboundController extends Controller
{
    use HandlesSnsMessages;

    public function __invoke(Request $request, SesObjectStore $store): JsonResponse
    {
        $message = $this->snsMessage($request);

        if ($message === null || ($message['notificationType'] ?? null) !== 'Received') {
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
}
