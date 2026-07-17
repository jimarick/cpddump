<?php

namespace App\Jobs;

use App\Ai\QuestionAnswerAgent;
use App\Ai\ReportWriterAgent;
use App\Enums\AiPurpose;
use App\Enums\ReportKind;
use App\Models\GeneratedReport;
use App\Services\AiGateway;
use App\Services\PortfolioDigest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var array<int, int> */
    public array $backoff = [30];

    public function __construct(public GeneratedReport $report) {}

    public function handle(AiGateway $ai, PortfolioDigest $digest): void
    {
        $report = $this->report->fresh(['user.profession', 'appraisalPeriod']);

        if (! $report || $report->status === 'ready') {
            return;
        }

        $user = $report->user;
        $period = $report->appraisalPeriod ?? $user->currentAppraisalPeriod();

        if (! $period) {
            $report->update(['status' => 'failed']);

            return;
        }

        $professionName = $user->profession->name ?? 'healthcare professional';
        $portfolio = $digest->build($user, $period);

        if ($report->kind === ReportKind::Question) {
            $notes = $report->params['notes'] ?? null;

            $prompt = collect([
                "The appraisal question:\n{$report->question}",
                filled($notes) ? "The user's own rough notes:\n{$notes}" : null,
                "The portfolio:\n{$portfolio}",
            ])->filter()->implode("\n\n");

            $response = $ai->structuredPrompt(
                agent: new QuestionAnswerAgent($professionName),
                user: $user,
                purpose: AiPurpose::QuestionAnswer,
                prompt: $prompt,
                generatable: $report,
            );

            $content = $response->toArray()['answer'] ?? '';
        } else {
            $prompt = "Period label: {$period->label}\n\nThe portfolio digest:\n{$portfolio}";

            $response = $ai->structuredPrompt(
                agent: new ReportWriterAgent($professionName),
                user: $user,
                purpose: AiPurpose::Report,
                prompt: $prompt,
                generatable: $report,
            );

            $content = $response->toArray()['markdown'] ?? '';
        }

        $report->update([
            'content' => $content,
            'status' => blank($content) ? 'failed' : 'ready',
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->report->fresh()?->update(['status' => 'failed']);
    }
}
