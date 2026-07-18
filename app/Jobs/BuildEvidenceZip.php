<?php

namespace App\Jobs;

use App\Models\GeneratedReport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

class BuildEvidenceZip implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public GeneratedReport $report) {}

    public function handle(): void
    {
        $report = $this->report->fresh(['user', 'appraisalPeriod']);

        if (! $report || $report->status === 'ready') {
            return;
        }

        $user = $report->user;
        $period = $report->appraisalPeriod;

        if (! $period) {
            $report->update(['status' => 'failed']);

            return;
        }

        $activities = $user->activities()
            ->where('appraisal_period_id', $period->id)
            ->with('attachments')
            ->orderBy('starts_on')
            ->get();

        $tmp = tempnam(sys_get_temp_dir(), 'cpd-zip-');

        if ($tmp === false) {
            $report->update(['status' => 'failed']);

            return;
        }

        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $files = 0;

        foreach ($activities as $activity) {
            $date = $activity->starts_on?->format('Y-m-d') ?? 'undated';
            $title = Str::limit(trim((string) preg_replace('#[\\\\/:*?"<>|]#', '', $activity->title)), 60, '');
            $folder = "{$date} {$title}";

            foreach ($activity->attachments as $attachment) {
                $contents = Storage::disk($attachment->disk)->get($attachment->path);

                if ($contents === null) {
                    continue;
                }

                $zip->addFromString("{$folder}/{$attachment->original_filename}", $contents);
                $files++;
            }
        }

        $zip->close();

        if ($files === 0) {
            @unlink($tmp);

            $report->update([
                'status' => 'failed',
                'params' => array_merge($report->params ?? [], [
                    'failure_reason' => 'No evidence files found for this appraisal year yet.',
                ]),
            ]);

            return;
        }

        $disk = config('filesystems.default');
        $path = "exports/{$user->id}/evidence-{$report->id}.zip";

        Storage::disk($disk)->put($path, (string) file_get_contents($tmp));
        @unlink($tmp);

        $report->update([
            'status' => 'ready',
            'content' => $path,
            'params' => array_merge($report->params ?? [], ['files' => $files]),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->report->fresh()?->update(['status' => 'failed']);
    }
}
