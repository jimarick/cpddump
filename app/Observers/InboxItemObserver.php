<?php

namespace App\Observers;

use App\Enums\InboxItemStatus;
use App\Models\InboxItem;
use App\Notifications\InboxItemFailed;
use App\Notifications\InboxItemReady;

/**
 * Pushes ride the status transition itself, so every path into Ready or
 * Failed notifies — analysis, the cached-duplicate shortcut, the failed()
 * job hook — without each call site remembering to. Updates only: items
 * born Ready (recurring drafts) are templates the user asked for, not
 * news worth a banner.
 */
class InboxItemObserver
{
    public function updated(InboxItem $item): void
    {
        if (! $item->wasChanged('status')) {
            return;
        }

        match ($item->status) {
            InboxItemStatus::Ready => $item->user->notify(new InboxItemReady($item)),
            InboxItemStatus::Failed => $item->user->notify(new InboxItemFailed($item)),
            default => null,
        };
    }
}
