<?php

namespace App\Http\Controllers\Api;

use App\Enums\EvidenceSource;
use App\Enums\InboxItemStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveInboxItemRequest;
use App\Models\Attachment;
use App\Models\InboxItem;
use App\Services\EvidenceIngestor;
use App\Services\MergeSuggester;
use App\Services\PidScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The companion app's inbox: capture (text notes, voice recordings, photos,
 * documents and links), plus list/review/approve/bin — mirroring the web
 * inbox so both clients share one lifecycle on the server.
 */
class InboxItemApiController extends Controller
{
    public function store(Request $request, EvidenceIngestor $ingestor): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255', 'required_without_all:files,audio,url,notes'],
            'details' => ['nullable', 'string', 'max:20000'],
            'notes' => ['nullable', 'string', 'max:50000'],
            'occurred_on' => ['nullable', 'date'],
            'url' => ['nullable', 'url', 'max:2048'],
            'audio' => ['nullable', 'file', 'max:51200', 'mimetypes:audio/webm,audio/ogg,audio/mpeg,audio/mp4,audio/wav,audio/x-m4a,video/webm'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => ['file', 'max:25600', 'mimes:'.implode(',', config('cpd.ingest.allowed_extensions'))],
        ]);

        $audio = $request->file('audio');
        $audio = is_array($audio) ? null : $audio;

        $uploaded = $request->file('files');
        $files = is_array($uploaded) ? array_values($uploaded) : [];

        if ($audio !== null) {
            $files[] = $audio;
        }

        $source = match (true) {
            filled($validated['notes'] ?? null) => EvidenceSource::Debrief,
            $audio !== null => EvidenceSource::VoiceNote,
            $files !== [] => EvidenceSource::Upload,
            filled($validated['url'] ?? null) => EvidenceSource::Link,
            default => EvidenceSource::Manual,
        };

        $item = $ingestor->ingest(
            user: $request->user(),
            source: $source,
            rawPayload: array_filter([
                'title' => $validated['title'] ?? null,
                'details' => $validated['details'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'occurred_on' => $validated['occurred_on'] ?? null,
                'url' => $validated['url'] ?? null,
            ]),
            files: $files,
        );

        if (! $item) {
            return response()->json(['message' => 'Matched an ignore rule.'], 200);
        }

        return response()->json([
            'id' => $item->id,
            'status' => $item->status->value,
        ], 201);
    }

    /** The open pile, newest first — the app's tray. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $openItems = $user->inboxItems()
            ->open()
            ->with('attachments:id,attachable_type,attachable_id,original_filename,mime_type,purged_at')
            ->latest()
            ->get();

        $suggestions = app(MergeSuggester::class)->forItems($user, $openItems);

        $items = $openItems->map(
            fn (InboxItem $item) => $this->serialise($item, suggestions: $suggestions[$item->id] ?? [])
        );

        return response()->json(['items' => $items]);
    }

    public function show(Request $request, InboxItem $item): JsonResponse
    {
        $this->authorizeItem($request, $item);

        $suggestions = app(MergeSuggester::class)->forItems($request->user(), collect([$item]));

        return response()->json(['item' => $this->serialise(
            $item,
            detailed: true,
            suggestions: $suggestions[$item->id] ?? [],
        )]);
    }

    public function approve(ApproveInboxItemRequest $request, InboxItem $item): JsonResponse
    {
        abort_if($item->isResolved(), 422, 'Already resolved.');

        // Same PII gate as the web: flagged content still held in a file or
        // the user's own text requires an explicit decision. The server is
        // the enforcement point — clients only surface it.
        if ($item->piiGateActive()) {
            if (! $request->boolean('pii_ack')) {
                return response()->json([
                    'message' => 'Possible personal information was found — remove it, or confirm you have checked it, before approving.',
                    'errors' => ['pii' => ['Possible personal information was found — remove it, or confirm you have checked it, before approving.']],
                ], 422);
            }

            $item->recordPiiResolution('affirmed');
        }

        $activity = $item->approve($request->validated());

        return response()->json([
            'activity_id' => $activity->id,
            'status' => $item->status->value,
        ]);
    }

    /**
     * "Remove personal info" — API parity with the web: purge stored files to
     * stubs and scrub NHS numbers from user-authored text, keeping the
     * identifier-free draft. Lifts the PII gate.
     */
    public function removePii(Request $request, InboxItem $item, PidScanner $scanner): JsonResponse
    {
        $this->authorizeItem($request, $item);

        $item->attachments()->whereNull('purged_at')->get()->each(function (Attachment $attachment) {
            $attachment->purgeToStub();
            $attachment->update(['extracted_text' => null]);
        });

        $payload = $item->raw_payload ?? [];

        foreach (['title', 'details', 'notes'] as $key) {
            if (is_string($payload[$key] ?? null)) {
                $payload[$key] = $scanner->scrubNhsNumbers($payload[$key])['text'];
            }
        }

        $item->update(['raw_payload' => $payload]);
        $item->recordPiiResolution('removed');

        return response()->json(['item' => $this->serialise($item->fresh('attachments'), detailed: true)]);
    }

    public function dismiss(Request $request, InboxItem $item): JsonResponse
    {
        $this->authorizeItem($request, $item);

        $validated = $request->validate([
            'ignore_rule' => ['nullable', 'array'],
            'ignore_rule.field' => ['required_with:ignore_rule', 'in:title,organiser,sender,sender_domain'],
            'ignore_rule.operator' => ['required_with:ignore_rule', 'in:equals,contains'],
            'ignore_rule.value' => ['required_with:ignore_rule', 'string', 'max:512'],
        ]);

        if (filled($validated['ignore_rule'] ?? null)) {
            $request->user()->ignoreRules()->create([
                'source' => $item->source,
                'field' => $validated['ignore_rule']['field'],
                'operator' => $validated['ignore_rule']['operator'],
                'value' => $validated['ignore_rule']['value'],
                'is_active' => true,
            ]);
        }

        $item->dismiss();

        // The row no longer exists — binned means deleted.
        return response()->json(['status' => 'dismissed', 'deleted' => true]);
    }

    public function retry(Request $request, InboxItem $item, EvidenceIngestor $ingestor): JsonResponse
    {
        $this->authorizeItem($request, $item);

        abort_if($item->isResolved(), 422);

        $item->update(['status' => InboxItemStatus::Pending, 'failure_reason' => null]);
        $ingestor->dispatchPipeline($item);

        return response()->json(['status' => $item->status->value]);
    }

    private function authorizeItem(Request $request, InboxItem $item): void
    {
        abort_unless($item->user_id === $request->user()->id, 403);
    }

    /**
     * @param  array<int, array<string, mixed>>  $suggestions
     * @return array<string, mixed>
     */
    private function serialise(InboxItem $item, bool $detailed = false, array $suggestions = []): array
    {
        return [
            'id' => $item->id,
            'source' => $item->source->value,
            'source_label' => $item->source->label(),
            'status' => $item->status->value,
            // Only what clients display — raw source text (email bodies,
            // transcripts) never ships; it is scrubbed post-analysis anyway.
            'raw_payload' => collect($item->raw_payload)->only(['title', 'subject', 'url', 'details', 'notes', 'occurred_on'])->all(),
            'pii_gate' => $item->piiGateActive(),
            'merge_suggestions' => $suggestions,
            'ai_analysis' => $item->ai_analysis,
            'ai_warnings' => $item->ai_warnings,
            'failure_reason' => $item->failure_reason,
            'created_at' => $item->created_at->toIso8601String(),
            'attachments' => $item->attachments->map(fn (Attachment $a) => [
                'id' => $a->id,
                'name' => $a->original_filename,
                'mime_type' => $a->mime_type,
                'purged' => $a->isPurged(),
            ] + ($detailed && ! $a->isPurged() ? ['url' => "/api/v1/attachments/{$a->id}"] : []))->all(),
        ];
    }
}
