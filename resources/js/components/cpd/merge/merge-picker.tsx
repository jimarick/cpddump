import { Layers, Loader2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { getJson } from '@/lib/api';
import type { MergeCandidates, MergeSeed } from '@/types/cpd';

/**
 * The non-drag door into merging: pick other timeline entries and inbox
 * items to combine with the one being reviewed/edited. Selecting exactly
 * one already-merged entry absorbs everything into it; selecting two is
 * refused (split one apart first).
 */
export function MergePickerDialog({
    baseLabel,
    baseIsMerged = false,
    exclude,
    periodId,
    onClose,
    onConfirm,
}: {
    /** Title of the item/activity the picker was opened from. */
    baseLabel: string;
    /** The base is itself a merged entry — everything absorbs into it. */
    baseIsMerged?: boolean;
    exclude: { activityIds: number[]; itemIds: number[] };
    periodId?: number | null;
    onClose: () => void;
    /** Selection only — the caller folds in its own base ids. */
    onConfirm: (selection: MergeSeed) => void;
}) {
    const [candidates, setCandidates] = useState<MergeCandidates | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [query, setQuery] = useState('');
    const [activityIds, setActivityIds] = useState<number[]>([]);
    const [itemIds, setItemIds] = useState<number[]>([]);

    useEffect(() => {
        getJson<MergeCandidates>(
            periodId
                ? `/merges/candidates?period_id=${periodId}`
                : '/merges/candidates',
        )
            .then(setCandidates)
            .catch((e: Error) => setError(e.message));
    }, [periodId]);

    const matches = (title: string) =>
        title.toLowerCase().includes(query.trim().toLowerCase());

    const activities = useMemo(
        () =>
            (candidates?.activities ?? []).filter(
                (a) =>
                    !exclude.activityIds.includes(a.id) && matches(a.title),
            ),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [candidates, query],
    );

    const items = useMemo(
        () =>
            (candidates?.inbox_items ?? []).filter(
                (i) => !exclude.itemIds.includes(i.id) && matches(i.title),
            ),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [candidates, query],
    );

    const selectedParents = (candidates?.activities ?? []).filter(
        (a) => a.merged && activityIds.includes(a.id),
    );

    const tooManyParents =
        selectedParents.length + (baseIsMerged ? 1 : 0) > 1;
    const total = activityIds.length + itemIds.length;

    const confirm = () => {
        const target =
            !baseIsMerged && selectedParents.length === 1
                ? selectedParents[0]
                : null;

        onConfirm({
            activity_ids: target
                ? activityIds.filter((id) => id !== target.id)
                : activityIds,
            inbox_item_ids: itemIds,
            into_activity_id: target?.id ?? null,
        });
    };

    const toggle = (list: number[], id: number) =>
        list.includes(id) ? list.filter((v) => v !== id) : [...list, id];

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="font-display text-xl font-extrabold">
                        Merge “{baseLabel}” with…
                    </DialogTitle>
                </DialogHeader>

                <Input
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Search your timeline and inbox…"
                    autoFocus
                />

                {error && (
                    <p className="text-sm text-red-600">{error}</p>
                )}

                {!candidates && !error && (
                    <div className="flex items-center gap-2 py-6 text-sm text-stone-500">
                        <Loader2 className="size-4 animate-spin" /> Looking
                        through your evidence…
                    </div>
                )}

                {candidates && (
                    <div className="grid gap-4">
                        {activities.length > 0 && (
                            <div className="grid gap-1">
                                <p className="text-[11px] font-bold tracking-[0.08em] text-stone-400 uppercase">
                                    On your timeline
                                </p>
                                {activities.map((a) => (
                                    <label
                                        key={a.id}
                                        className="flex cursor-pointer items-center gap-2.5 rounded-lg px-2 py-1.5 text-[13px] hover:bg-[#fffbf8]"
                                    >
                                        <Checkbox
                                            checked={activityIds.includes(
                                                a.id,
                                            )}
                                            onCheckedChange={() =>
                                                setActivityIds((ids) =>
                                                    toggle(ids, a.id),
                                                )
                                            }
                                        />
                                        <span
                                            className="size-2.5 shrink-0 rounded-full border border-ink"
                                            style={{
                                                backgroundColor: a.type.color,
                                            }}
                                        />
                                        <span className="min-w-0 flex-1 truncate font-semibold">
                                            {a.title}
                                            {a.merged && (
                                                <Layers className="ml-1.5 inline size-3 text-stone-400" />
                                            )}
                                        </span>
                                        <span className="shrink-0 text-[11.5px] text-stone-400">
                                            {a.starts_on ?? '—'} ·{' '}
                                            {a.cpd_points} pts
                                        </span>
                                    </label>
                                ))}
                            </div>
                        )}

                        {items.length > 0 && (
                            <div className="grid gap-1">
                                <p className="text-[11px] font-bold tracking-[0.08em] text-stone-400 uppercase">
                                    In your inbox
                                </p>
                                {items.map((i) => (
                                    <label
                                        key={i.id}
                                        className="flex cursor-pointer items-center gap-2.5 rounded-lg px-2 py-1.5 text-[13px] hover:bg-[#fffbf8]"
                                    >
                                        <Checkbox
                                            checked={itemIds.includes(i.id)}
                                            onCheckedChange={() =>
                                                setItemIds((ids) =>
                                                    toggle(ids, i.id),
                                                )
                                            }
                                        />
                                        <span className="min-w-0 flex-1 truncate font-semibold">
                                            {i.title}
                                        </span>
                                        <span className="shrink-0 text-[11.5px] text-stone-400">
                                            {i.source_label} ·{' '}
                                            {i.starts_on ?? '—'}
                                        </span>
                                    </label>
                                ))}
                            </div>
                        )}

                        {activities.length === 0 && items.length === 0 && (
                            <p className="py-4 text-center text-sm text-stone-500">
                                Nothing else to merge with
                                {query ? ' that matches your search' : ''}.
                            </p>
                        )}
                    </div>
                )}

                {tooManyParents && (
                    <p className="text-[12.5px] font-semibold text-red-600">
                        Two merged entries can't merge into each other — split
                        one apart first.
                    </p>
                )}

                <div className="flex items-center gap-2 border-t border-dashed border-stone-300 pt-3">
                    <Button
                        onClick={confirm}
                        disabled={total === 0 || tooManyParents}
                        className="border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
                    >
                        Continue — merge {total + 1}
                    </Button>
                    <Button
                        variant="outline"
                        onClick={onClose}
                        className="border-2 border-ink"
                    >
                        Cancel
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
