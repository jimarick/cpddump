import { Check, PenLine, Plus, X } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { Takeaway } from '@/types/cpd';
import type { EvidenceFormValues } from './evidence-form-fields';

const MAX_ITEMS = 15;

type Kind = 'nugget' | 'action';

/**
 * The wizard's takeaways page: the AI's suggestions shown as a tilted card
 * grid, ALL deselected by default — approve keeps only what the user
 * selects and silently discards the rest. No generate/discard buttons;
 * selection is the whole model. Cards are tap-to-select, pencil-to-edit;
 * the dotted ＋ card adds a user-authored one (auto-selected).
 */
export function TakeawaysStepFields({
    values,
    onChange,
}: {
    values: EvidenceFormValues;
    onChange: (patch: Partial<EvidenceFormValues>) => void;
}) {
    const [editingId, setEditingId] = useState<string | null>(null);
    const [adding, setAdding] = useState(false);
    const [draft, setDraft] = useState('');
    const [draftKind, setDraftKind] = useState<Kind>('nugget');

    const all: { item: Takeaway; kind: Kind }[] = [
        ...values.nuggets.map((item) => ({ item, kind: 'nugget' as const })),
        ...values.actions.map((item) => ({ item, kind: 'action' as const })),
    ];

    const selected = new Set(values.selected_takeaway_ids);
    const allSelected = all.length > 0 && all.every(({ item }) => selected.has(item.id));

    const toggle = (id: string) =>
        onChange({
            selected_takeaway_ids: selected.has(id)
                ? values.selected_takeaway_ids.filter((s) => s !== id)
                : [...values.selected_takeaway_ids, id],
        });

    const selectAll = () =>
        onChange({
            selected_takeaway_ids: allSelected
                ? []
                : all.map(({ item }) => item.id),
        });

    const patchList = (kind: Kind, items: Takeaway[]): Partial<EvidenceFormValues> =>
        kind === 'nugget' ? { nuggets: items } : { actions: items };

    const commitEdit = (kind: Kind, id: string, text: string) => {
        const items = kind === 'nugget' ? values.nuggets : values.actions;
        const trimmed = text.trim();

        onChange({
            ...patchList(
                kind,
                trimmed === ''
                    ? items.filter((i) => i.id !== id)
                    : items.map((i) => (i.id === id ? { ...i, text: trimmed } : i)),
            ),
            ...(trimmed === ''
                ? { selected_takeaway_ids: values.selected_takeaway_ids.filter((s) => s !== id) }
                : {}),
        });
        setEditingId(null);
    };

    const commitAdd = () => {
        const trimmed = draft.trim();

        if (trimmed !== '') {
            const items = draftKind === 'nugget' ? values.nuggets : values.actions;
            const id = crypto.randomUUID();
            onChange({
                ...patchList(draftKind, [
                    ...items,
                    { id, text: trimmed, done: false },
                ]),
                // You wrote it, you want it — added items arrive selected.
                selected_takeaway_ids: [...values.selected_takeaway_ids, id],
            });
        }

        setDraft('');
        setAdding(false);
    };

    const canAdd =
        values.nuggets.length < MAX_ITEMS || values.actions.length < MAX_ITEMS;

    /** The brand tilt, alternating so the grid reads as a pinboard. */
    const tilt = (index: number) =>
        ['rotate-[0.9deg]', '-rotate-[0.8deg]', 'rotate-[0.4deg]', '-rotate-[0.5deg]'][index % 4];

    return (
        <div className="grid gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="max-w-md">
                    <p className="text-[15.5px] font-bold text-ink">
                        Select the ones you want to store
                    </p>
                    <p className="mt-0.5 text-[13px] leading-relaxed text-stone-600">
                        They're fed back to you as notifications and weekly
                        recaps to reinforce your learning. Anything left
                        unselected is discarded when you approve.
                    </p>
                </div>
                {all.length > 0 && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={selectAll}
                        className="border-2 border-ink font-bold"
                    >
                        <Check className="size-3.5" />{' '}
                        {allSelected ? 'Deselect all' : 'Select all'}
                    </Button>
                )}
            </div>

            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                {all.map(({ item, kind }, index) => {
                    const isSelected = selected.has(item.id);

                    return (
                        <div
                            key={item.id}
                            className={cn(
                                'relative flex min-h-24 rounded-[10px] border-2 bg-white p-2.5 text-[12.5px] leading-snug transition-all',
                                tilt(index),
                                // Grey until chosen: selecting colours the
                                // border by kind and darkens the text.
                                isSelected
                                    ? cn(
                                          'text-ink shadow-[3px_3px_0_rgba(28,25,23,.55)]',
                                          kind === 'action'
                                              ? 'border-brand'
                                              : 'border-ink',
                                      )
                                    : 'border-stone-300 text-stone-400',
                            )}
                        >
                            {isSelected && (
                                <span
                                    className={cn(
                                        'absolute -top-2 -right-2 flex size-5 items-center justify-center rounded-full border-2 border-ink text-white',
                                        kind === 'action' ? 'bg-brand' : 'bg-ink',
                                    )}
                                >
                                    <Check className="size-3" />
                                </span>
                            )}
                            {editingId === item.id ? (
                                <textarea
                                    autoFocus
                                    defaultValue={item.text}
                                    onBlur={(e) =>
                                        commitEdit(kind, item.id, e.target.value)
                                    }
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            e.preventDefault();
                                            commitEdit(
                                                kind,
                                                item.id,
                                                e.currentTarget.value,
                                            );
                                        }

                                        if (e.key === 'Escape') {
                                            setEditingId(null);
                                        }
                                    }}
                                    className="w-full resize-none bg-transparent text-[12.5px] leading-snug focus:outline-none"
                                    rows={4}
                                />
                            ) : (
                                <>
                                    <button
                                        type="button"
                                        onClick={() => toggle(item.id)}
                                        title={
                                            isSelected
                                                ? 'Deselect — discard on approve'
                                                : 'Select — keep this one'
                                        }
                                        className="min-w-0 flex-1 cursor-pointer pr-4 text-left"
                                    >
                                        {item.text}
                                    </button>
                                    <button
                                        type="button"
                                        title="Edit"
                                        onClick={() => setEditingId(item.id)}
                                        className="absolute right-1.5 bottom-1.5 cursor-pointer text-stone-300 hover:text-ink"
                                    >
                                        <PenLine className="size-3.5" />
                                    </button>
                                </>
                            )}
                        </div>
                    );
                })}

                {adding ? (
                    <div
                        className={cn(
                            'flex min-h-24 flex-col gap-1.5 rounded-[10px] border-2 border-dashed bg-white p-2.5',
                            draftKind === 'action' ? 'border-brand' : 'border-stone-400',
                        )}
                    >
                        <textarea
                            autoFocus
                            value={draft}
                            onChange={(e) => setDraft(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    commitAdd();
                                }

                                if (e.key === 'Escape') {
                                    setDraft('');
                                    setAdding(false);
                                }
                            }}
                            placeholder={
                                draftKind === 'nugget'
                                    ? 'A new nugget…'
                                    : 'A new action…'
                            }
                            className="w-full flex-1 resize-none bg-transparent text-[12.5px] leading-snug focus:outline-none"
                        />
                        <div className="flex items-center justify-between">
                            <button
                                type="button"
                                onClick={() =>
                                    setDraftKind((k) =>
                                        k === 'nugget' ? 'action' : 'nugget',
                                    )
                                }
                                title="Switch between nugget and action"
                                className={cn(
                                    'cursor-pointer rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase',
                                    draftKind === 'action'
                                        ? 'border-brand text-brand-dark'
                                        : 'border-ink text-ink',
                                )}
                            >
                                {draftKind}
                            </button>
                            <span className="flex items-center gap-1.5">
                                <button
                                    type="button"
                                    title="Cancel"
                                    onClick={() => {
                                        setDraft('');
                                        setAdding(false);
                                    }}
                                    className="cursor-pointer text-stone-300 hover:text-red-600"
                                >
                                    <X className="size-4" />
                                </button>
                                <button
                                    type="button"
                                    title="Add"
                                    onClick={commitAdd}
                                    className="cursor-pointer text-stone-400 hover:text-ink"
                                >
                                    <Check className="size-4" />
                                </button>
                            </span>
                        </div>
                    </div>
                ) : (
                    canAdd && (
                        <button
                            type="button"
                            title="Add a takeaway"
                            onClick={() => setAdding(true)}
                            className={cn(
                                'flex min-h-24 cursor-pointer items-center justify-center rounded-[10px] border-2 border-dashed border-stone-400 text-stone-400 hover:border-ink hover:text-ink',
                                tilt(all.length),
                            )}
                        >
                            <Plus className="size-5" />
                        </button>
                    )
                )}
            </div>

        </div>
    );
}
