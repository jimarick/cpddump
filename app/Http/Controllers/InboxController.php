<?php

namespace App\Http\Controllers;

use App\Models\ActivityType;
use App\Models\Attachment;
use App\Models\Recurrence;
use App\Services\StatsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    public function index(Request $request, StatsService $stats): Response
    {
        $user = $request->user();
        $profession = $user->profession;
        $period = $user->currentAppraisalPeriod();

        $items = $user->inboxItems()
            ->open()
            ->with('attachments:id,attachable_type,attachable_id,original_filename,mime_type')
            ->latest()
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'source' => $item->source->value,
                'source_label' => $item->source->label(),
                'status' => $item->status->value,
                'raw_payload' => $item->raw_payload,
                'ai_analysis' => $item->ai_analysis,
                'ai_warnings' => $item->ai_warnings,
                'failure_reason' => $item->failure_reason,
                'created_at' => $item->created_at->toIso8601String(),
                'attachments' => $item->attachments->map(fn (Attachment $a) => [
                    'id' => $a->id,
                    'name' => $a->original_filename,
                    'mime_type' => $a->mime_type,
                ])->all(),
            ]);

        return Inertia::render('inbox', [
            'items' => $items,
            'stats' => $stats->forPeriod($user, $period),
            'period' => $period?->only(['id', 'label', 'starts_on', 'ends_on']),
            'reference' => [
                'activityTypes' => ActivityType::availableTo($profession)->get(['id', 'slug', 'name', 'color', 'icon']),
                'categories' => $profession?->categories()->get(['id', 'slug', 'name']) ?? [],
                'domains' => $profession?->frameworkDomains()->with('frameworkAttributes:id,framework_domain_id,code,name')->get(['id', 'code', 'name']) ?? [],
                'reflectionPrompts' => $profession?->reflectionPrompts() ?? [],
                'projects' => $user->projects()->where('status', 'open')->get(['id', 'title', 'kind']),
            ],
            'dumpAddress' => $user->inboundEmailAddress(),
            'recurrences' => $user->recurrences()
                ->with('type:id,slug,name')
                ->orderBy('created_at')
                ->get()
                ->map(fn (Recurrence $r) => [
                    'id' => $r->id,
                    'kind' => $r->kind,
                    'title' => $r->title,
                    'type' => $r->type?->name,
                    'frequency' => $r->frequency,
                    'expected_per_year' => $r->expected_per_year,
                    'reminder' => $r->reminder,
                    'is_active' => $r->is_active,
                    'captured' => $r->kind === 'expectation' && $period
                        ? $r->activities()->where('appraisal_period_id', $period->id)->count()
                        : null,
                ]),
        ]);
    }
}
