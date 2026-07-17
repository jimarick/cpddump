<?php

namespace App\Jobs;

use App\Models\InboxItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Throwable;

class ExtractAttachmentText implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public InboxItem $item) {}

    public function handle(): void
    {
        $item = $this->item->fresh('attachments');

        if (! $item) {
            return;
        }

        foreach ($item->attachments as $attachment) {
            if (! $attachment->isPdf() || filled($attachment->extracted_text)) {
                continue;
            }

            try {
                $contents = Storage::disk($attachment->disk)->get($attachment->path);
                $text = (new Parser)->parseContent($contents)->getText();

                $attachment->update(['extracted_text' => trim($text) ?: null]);
            } catch (Throwable) {
                // Scanned/image-only PDF: leave extracted_text null and the
                // analyst will read the document directly via the model.
            }
        }

        AnalyzeInboxItem::dispatch($item);
    }
}
