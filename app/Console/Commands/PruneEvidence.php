<?php

namespace App\Console\Commands;

use App\Enums\ReportKind;
use App\Models\Attachment;
use App\Models\GeneratedReport;
use App\Models\InboxItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PruneEvidence extends Command
{
    protected $signature = 'cpd:prune-evidence';

    protected $description = 'Safety-net cleanup: redact stragglers, remove orphaned files, expire old exports, prune old failed jobs';

    public function handle(): int
    {
        $this->redactStragglers();
        $this->deleteOrphanedFiles();
        $this->expireOldExports();
        $this->pruneFailedJobs();

        return self::SUCCESS;
    }

    /**
     * Resolved items normally redact at approve/dismiss time; this catches
     * anything resolved before that behaviour existed (or via edge paths).
     */
    private function redactStragglers(): void
    {
        $count = 0;

        InboxItem::query()
            ->whereIn('status', ['approved', 'dismissed'])
            ->where('resolved_at', '<', now()->subDays(7))
            ->eachById(function (InboxItem $item) use (&$count) {
                $before = $item->raw_payload;
                $item->redactPayload();

                if ($item->raw_payload !== $before) {
                    $count++;
                }
            });

        $this->info("Redacted {$count} resolved payloads.");
    }

    /** Files on disk that no attachment row points at any more. */
    private function deleteOrphanedFiles(): void
    {
        $disk = Storage::disk(config('filesystems.default'));
        $known = Attachment::query()->pluck('path')->flip();
        $deleted = 0;

        foreach ($disk->allFiles('evidence') as $path) {
            if (! isset($known[$path])) {
                $disk->delete($path);
                $deleted++;
            }
        }

        $this->info("Deleted {$deleted} orphaned evidence files.");
    }

    /** Evidence zips are point-in-time bundles — stale after a month. */
    private function expireOldExports(): void
    {
        $expired = 0;

        GeneratedReport::query()
            ->where('kind', ReportKind::EvidenceZip)
            ->where('created_at', '<', now()->subDays(30))
            ->eachById(function (GeneratedReport $report) use (&$expired) {
                $report->delete(); // model hook removes the zip file
                $expired++;
            });

        $this->info("Expired {$expired} old evidence exports.");
    }

    private function pruneFailedJobs(): void
    {
        $pruned = DB::table('failed_jobs')
            ->where('failed_at', '<', now()->subDays(30))
            ->delete();

        $this->info("Pruned {$pruned} old failed jobs.");
    }
}
