<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for Resend's received-email API: webhooks deliver metadata
 * only, so body and attachments are fetched here.
 */
class ResendInbound
{
    /**
     * The full received email: subject, from, to, text, html, attachments.
     *
     * @return array<string, mixed>
     */
    public function email(string $emailId): array
    {
        return $this->client()
            ->get("https://api.resend.com/emails/receiving/{$emailId}")
            ->throw()
            ->json();
    }

    /**
     * Attachment metadata with short-lived download_url entries.
     *
     * @return array<int, array<string, mixed>>
     */
    public function attachments(string $emailId): array
    {
        $response = $this->client()
            ->get("https://api.resend.com/emails/receiving/{$emailId}/attachments")
            ->throw()
            ->json();

        return $response['data'] ?? (array_is_list((array) $response) ? $response : []);
    }

    public function download(string $url): string
    {
        return Http::timeout(60)->get($url)->throw()->body();
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) config('services.resend.key'))->timeout(30);
    }
}
