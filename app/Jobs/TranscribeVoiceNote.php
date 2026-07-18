<?php

namespace App\Jobs;

use App\Models\InboxItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Transcription;

class TranscribeVoiceNote implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var array<int, int> */
    public array $backoff = [30];

    public function __construct(public InboxItem $item) {}

    public function handle(): void
    {
        $item = $this->item->fresh('attachments');

        if (! $item || $item->isResolved()) {
            return;
        }

        $audio = $item->attachments->first(fn ($a) => str_starts_with($a->mime_type, 'audio/') || $a->mime_type === 'video/webm');

        if ($audio && blank($item->raw_payload['transcript'] ?? null)) {
            $response = Transcription::of(Audio::fromStorage($audio->path, $audio->disk))->generate();

            $item->update([
                'raw_payload' => array_merge($item->raw_payload, ['transcript' => trim($response->text)]),
            ]);
        }

        AnalyzeInboxItem::dispatch($item);
    }
}
