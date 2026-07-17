<?php

namespace App\Jobs;

use App\Models\InboxItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class FetchLinkContent implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var array<int, int> */
    public array $backoff = [15];

    public function __construct(public InboxItem $item) {}

    public function handle(): void
    {
        $item = $this->item->fresh();

        if (! $item || $item->isResolved()) {
            return;
        }

        $url = $item->raw_payload['url'] ?? null;

        if (! $url) {
            AnalyzeInboxItem::dispatch($item);

            return;
        }

        $response = Http::timeout(20)
            ->withHeaders(['User-Agent' => 'CPDDump/1.0 (+https://cpddump.com)'])
            ->get($url);

        $html = $response->body();

        $item->update([
            'raw_payload' => array_merge($item->raw_payload, [
                'page_title' => $this->extractTitle($html),
                'page_text' => $this->htmlToText($html),
                'fetched_at' => now()->toIso8601String(),
            ]),
        ]);

        AnalyzeInboxItem::dispatch($item);
    }

    public function failed(?Throwable $exception): void
    {
        // Analyse with whatever we have (the bare URL and user note).
        $item = $this->item->fresh();

        if ($item && ! $item->isResolved()) {
            AnalyzeInboxItem::dispatch($item);
        }
    }

    private function extractTitle(string $html): ?string
    {
        return preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $m)
            ? trim(html_entity_decode($m[1]))
            : null;
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('/<(script|style|nav|footer|header|aside)\b[^>]*>.*?<\/\1>/si', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html));
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return Str::limit(trim($text), 40_000, '');
    }
}
