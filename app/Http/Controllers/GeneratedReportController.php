<?php

namespace App\Http\Controllers;

use App\Enums\ReportKind;
use App\Jobs\BuildEvidenceZip;
use App\Jobs\GenerateReport;
use App\Models\GeneratedReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GeneratedReportController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $reports = $user->generatedReports()
            ->with('appraisalPeriod:id,label')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (GeneratedReport $r) => [
                'id' => $r->id,
                'kind' => $r->kind->value,
                'question' => $r->question,
                'status' => $r->status,
                'failure_reason' => $r->params['failure_reason'] ?? null,
                'files' => $r->params['files'] ?? null,
                'content' => $r->status === 'ready' && $r->kind !== ReportKind::EvidenceZip ? $r->content : null,
                'period' => $r->appraisalPeriod?->label,
                'created_at' => $r->created_at->toIso8601String(),
            ]);

        return Inertia::render('reports/index', [
            'reports' => $reports,
            'period' => $user->currentAppraisalPeriod()?->only(['id', 'label']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'in:question,report'],
            'question' => ['required_if:kind,question', 'nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        $user = $request->user();
        $period = $user->currentAppraisalPeriod();

        abort_unless($period !== null, 422, 'No current appraisal period.');

        $report = $user->generatedReports()->create([
            'appraisal_period_id' => $period->id,
            'kind' => ReportKind::from($validated['kind']),
            'question' => $validated['question'] ?? null,
            'params' => filled($validated['notes'] ?? null) ? ['notes' => $validated['notes']] : [],
            'status' => 'pending',
        ]);

        GenerateReport::dispatch($report);

        return back()->with('success', $report->kind === ReportKind::Question
            ? 'Drafting your answer…'
            : 'Writing your report — this takes a minute…');
    }

    public function exportEvidence(Request $request): RedirectResponse
    {
        $user = $request->user();
        $period = $user->currentAppraisalPeriod();

        abort_unless($period !== null, 422, 'No current appraisal period.');

        $report = $user->generatedReports()->create([
            'appraisal_period_id' => $period->id,
            'kind' => ReportKind::EvidenceZip,
            'params' => [],
            'status' => 'pending',
        ]);

        BuildEvidenceZip::dispatch($report);

        return back()->with('success', 'Bundling your evidence — the download appears below when ready.');
    }

    public function download(Request $request, GeneratedReport $report): StreamedResponse
    {
        abort_unless($report->user_id === $request->user()->id, 404);
        abort_unless(
            $report->kind === ReportKind::EvidenceZip
                && $report->status === 'ready'
                && filled($report->content),
            404,
        );

        $label = str_replace(['/', ' '], '-', $report->appraisalPeriod->label ?? 'period');

        return Storage::disk(config('filesystems.default'))
            ->download($report->content, "cpd-evidence-{$label}.zip");
    }

    public function destroy(Request $request, GeneratedReport $report): RedirectResponse
    {
        abort_unless($report->user_id === $request->user()->id, 403);

        $report->delete();

        return back()->with('success', 'Deleted.');
    }
}
