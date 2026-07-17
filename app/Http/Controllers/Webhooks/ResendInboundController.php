<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundEmail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class ResendInboundController extends Controller
{
    public function __invoke(Request $request): Response
    {
        abort_unless($this->hasValidSignature($request), 401);

        $payload = $request->json()->all();

        if (($payload['type'] ?? null) !== 'email.received') {
            return response()->noContent();
        }

        $data = $payload['data'] ?? [];
        $user = $this->resolveUser($data);

        // Unknown dump address: acknowledge and drop silently — bouncing
        // would leak which addresses exist.
        if (! $user) {
            return response()->noContent();
        }

        ProcessInboundEmail::dispatch(
            $user->id,
            (string) ($data['email_id'] ?? ''),
            [
                'subject' => (string) ($data['subject'] ?? ''),
                'from' => (string) ($data['from'] ?? ''),
                'message_id' => (string) ($data['message_id'] ?? ''),
            ],
        );

        return response()->noContent();
    }

    /** @param array<string, mixed> $data */
    private function resolveUser(array $data): ?User
    {
        $domain = mb_strtolower((string) config('cpd.inbound_email_domain'));

        $recipients = collect([...(array) ($data['to'] ?? []), ...(array) ($data['received_for'] ?? [])])
            ->map(fn ($address) => mb_strtolower(trim((string) $address)));

        foreach ($recipients as $address) {
            if (! str_ends_with($address, '@'.$domain)) {
                continue;
            }

            $token = Str::before($address, '@');
            $user = User::where('inbound_email_token', $token)->first();

            if ($user) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Svix-style verification: base64 HMAC-SHA256 of "id.timestamp.body"
     * with the whsec_ secret, matched against any v1 signature offered.
     */
    private function hasValidSignature(Request $request): bool
    {
        $secret = (string) config('services.resend.inbound_webhook_secret');

        // Explicitly unconfigured (local/testing): accept, so the flow can
        // be exercised without a live Resend account.
        if ($secret === '') {
            return app()->environment('local', 'testing');
        }

        $id = (string) $request->header('svix-id');
        $timestamp = (string) $request->header('svix-timestamp');
        $signatures = (string) $request->header('svix-signature');

        if ($id === '' || $timestamp === '' || $signatures === '') {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > 300) {
            return false;
        }

        $key = base64_decode(Str::after($secret, 'whsec_'));
        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$request->getContent()}", $key, true));

        foreach (explode(' ', $signatures) as $candidate) {
            $value = Str::contains($candidate, ',') ? Str::after($candidate, ',') : $candidate;

            if (hash_equals($expected, $value)) {
                return true;
            }
        }

        return false;
    }
}
