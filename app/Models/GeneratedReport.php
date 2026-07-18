<?php

namespace App\Models;

use App\Enums\ReportKind;
use Database\Factories\GeneratedReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $appraisal_period_id
 * @property ReportKind $kind
 * @property string|null $question
 * @property array<string, mixed> $params
 * @property string|null $content
 * @property string $status
 */
class GeneratedReport extends Model
{
    /** @use HasFactory<GeneratedReportFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        // Evidence zips live on disk; deleting the row deletes the file.
        static::deleted(function (GeneratedReport $report): void {
            if ($report->kind === ReportKind::EvidenceZip && filled($report->content)) {
                Storage::disk(config('filesystems.default'))->delete($report->content);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => ReportKind::class,
            'params' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<AppraisalPeriod, $this> */
    public function appraisalPeriod(): BelongsTo
    {
        return $this->belongsTo(AppraisalPeriod::class);
    }
}
