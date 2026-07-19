<?php

namespace App\Services\Apns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal token-authenticated APNs client: an ES256 provider JWT signed
 * with the .p8 key, sent over HTTP/2. No SDK — the whole protocol is one
 * POST per device.
 */
class ApnsClient
{
    /** @param  array<string, mixed>  $payload */
    public function push(string $deviceToken, array $payload): ApnsResponse
    {
        $response = Http::withToken($this->providerJwt())
            ->withHeaders([
                'apns-topic' => config('services.apns.bundle_id'),
                'apns-push-type' => 'alert',
                'apns-priority' => '10',
            ])
            ->withOptions(['version' => 2.0])
            ->post($this->host()."/3/device/{$deviceToken}", $payload);

        $reason = $response->json('reason');

        return new ApnsResponse($response->status(), is_string($reason) ? $reason : null);
    }

    private function host(): string
    {
        return config('services.apns.environment') === 'production'
            ? 'https://api.push.apple.com'
            : 'https://api.sandbox.push.apple.com';
    }

    /**
     * APNs accepts provider tokens between 0 and 60 minutes old, and
     * throttles apps that mint one per push — cache for 50 minutes.
     */
    private function providerJwt(): string
    {
        return Cache::remember('apns.provider-jwt', now()->addMinutes(50), fn () => $this->mintJwt());
    }

    private function mintJwt(): string
    {
        $key = openssl_pkey_get_private($this->privateKey());

        throw_unless($key, new RuntimeException('APNs private key is missing or unreadable.'));

        $header = $this->encode(['alg' => 'ES256', 'kid' => config('services.apns.key_id')]);
        $claims = $this->encode(['iss' => config('services.apns.team_id'), 'iat' => time()]);

        openssl_sign("{$header}.{$claims}", $der, $key, OPENSSL_ALGO_SHA256);

        $signature = rtrim(strtr(base64_encode($this->derToJose($der)), '+/', '-_'), '=');

        return "{$header}.{$claims}.{$signature}";
    }

    private function privateKey(): string
    {
        // Key contents in the env (Laravel Cloud) beat a file on disk (local).
        if (filled($contents = config('services.apns.private_key'))) {
            return str_replace('\n', "\n", $contents);
        }

        $path = config('services.apns.private_key_path');

        throw_if(blank($path), new RuntimeException('Neither APNS_PRIVATE_KEY nor APNS_PRIVATE_KEY_PATH is set.'));

        $contents = @file_get_contents(str_starts_with($path, '/') ? $path : base_path($path));

        throw_if($contents === false, new RuntimeException("APNs key file not found at {$path}."));

        return $contents;
    }

    /** @param  array<string, mixed>  $data */
    private function encode(array $data): string
    {
        return rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * openssl emits an ASN.1 DER sequence of two integers; JWT ES256 wants
     * the raw 64-byte r‖s concatenation, each half left-padded to 32 bytes.
     */
    private function derToJose(string $der): string
    {
        $pos = 1;

        // Skip the SEQUENCE length (long form uses extra bytes).
        if ((ord($der[$pos]) & 0x80) !== 0) {
            $pos += ord($der[$pos]) & 0x7F;
        }
        $pos++;

        $jose = '';

        foreach ([0, 1] as $ignored) {
            $pos++; // INTEGER tag
            $length = ord($der[$pos++]);
            $integer = ltrim(substr($der, $pos, $length), "\x00");
            $pos += $length;

            $jose .= str_pad($integer, 32, "\x00", STR_PAD_LEFT);
        }

        return $jose;
    }
}
