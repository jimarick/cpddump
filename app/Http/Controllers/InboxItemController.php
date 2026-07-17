<?php

namespace App\Http\Controllers;

use App\Enums\EvidenceSource;
use App\Enums\InboxItemStatus;
use App\Http\Requests\ApproveInboxItemRequest;
use App\Http\Requests\StoreInboxItemRequest;
use App\Models\InboxItem;
use App\Services\EvidenceIngestor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InboxItemController extends Controller
{
    public function store(StoreInboxItemRequest $request, EvidenceIngestor $ingestor): RedirectResponse
    {
        $validated = $request->validated();

        $source = match (true) {
            $request->hasFile('files') => EvidenceSource::Upload,
            filled($validated['url'] ?? null) => EvidenceSource::Link,
            default => EvidenceSource::Manual,
        };

        $payload = collect($validated)->only(['title', 'details', 'url'])->filter()->all();

        $item = $ingestor->ingest(
            $request->user(),
            $source,
            $payload,
            $request->file('files', []),
        );

        return back()->with('success', $item ? 'Dumped. The AI is reading it now.' : 'That one matched an ignore rule.');
    }

    public function approve(ApproveInboxItemRequest $request, InboxItem $item): RedirectResponse
    {
        $item->approve($request->validated());

        return back()->with('success', 'Approved — added to your timeline.');
    }

    public function dismiss(Request $request, InboxItem $item): RedirectResponse
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

        return back()->with('success', 'Binned.');
    }

    public function retry(Request $request, InboxItem $item, EvidenceIngestor $ingestor): RedirectResponse
    {
        $this->authorizeItem($request, $item);

        abort_if($item->isResolved(), 422);

        $item->update(['status' => InboxItemStatus::Pending, 'failure_reason' => null]);
        $ingestor->dispatchPipeline($item);

        return back()->with('success', 'Retrying analysis…');
    }

    private function authorizeItem(Request $request, InboxItem $item): void
    {
        abort_unless($item->user_id === $request->user()->id, 403);
    }
}
