import {
    DndContext,
    PointerSensor,
    useDraggable,
    useDroppable,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type { DragEndEvent } from '@dnd-kit/core';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowUp, Bell, Gem, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { CaveatNote } from '@/components/brand/caveat-note';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import type { PeriodData, Takeaway } from '@/types/cpd';

interface TakeawayActivity {
    id: number;
    title: string;
    starts_on: string | null;
    type: { slug: string; name: string; color: string; icon: string };
    nuggets: Takeaway[];
    actions: Takeaway[];
    has_source_notes: boolean;
    source_notes: string | null;
}

interface Props {
    period: PeriodData | null;
    activities: TakeawayActivity[];
}

type Kind = 'nugget' | 'action';

interface WallItem {
    activity: TakeawayActivity;
    item: Takeaway;
    kind: Kind;
}

/** `activityId:itemId` — dnd-kit ids must be strings. */
const dragId = (w: WallItem) => `${w.activity.id}:${w.item.id}`;

function patchItem(
    w: WallItem,
    patch: { done?: boolean; kind?: Kind },
): void {
    router.patch(
        `/activities/${w.activity.id}/takeaways/${w.item.id}`,
        patch,
        { preserveScroll: true },
    );
}

export default function Takeaways({ period, activities }: Props) {
    const [openActivityId, setOpenActivityId] = useState<number | null>(null);

    const all: WallItem[] = activities.flatMap((activity) => [
        ...activity.nuggets.map((item) => ({
            activity,
            item,
            kind: 'nugget' as const,
        })),
        ...activity.actions.map((item) => ({
            activity,
            item,
            kind: 'action' as const,
        })),
    ]);

    const openNuggets = all.filter((w) => w.kind === 'nugget' && !w.item.done);
    const openActions = all.filter((w) => w.kind === 'action' && !w.item.done);
    const done = all.filter((w) => w.item.done);

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    );

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        if (!over) {
            return;
        }

        const dragged = all.find((w) => dragId(w) === active.id);

        if (!dragged) {
            return;
        }

        if (over.id === 'zone-completed' && !dragged.item.done) {
            patchItem(dragged, { done: true });
        } else if (over.id === 'zone-open' && dragged.item.done) {
            patchItem(dragged, { done: false });
        }
    };

    const openActivity =
        activities.find((a) => a.id === openActivityId) ?? null;

    return (
        <>
            <Head title="Takeaways" />

            <div className="mx-auto w-full max-w-[1080px] px-4 pt-6 pb-16 md:px-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="font-display text-[32px] leading-none font-extrabold tracking-[-0.03em]">
                            Takeaways
                        </h1>
                        <p className="mt-1 flex flex-wrap gap-x-4 text-[12.5px] font-semibold text-stone-500">
                        {period && <span>{period.label}</span>}
                        <span>
                            {openNuggets.length} nugget
                            {openNuggets.length === 1 ? '' : 's'}
                        </span>
                        <span>
                            {openActions.length} action
                            {openActions.length === 1 ? '' : 's'} open
                        </span>
                        {done.length > 0 && <span>{done.length} done</span>}
                        </p>
                    </div>
                    <Link
                        href="/settings/notifications"
                        className="inline-flex items-center gap-1.5 rounded-md border-2 border-ink bg-brand px-3.5 py-1.5 text-sm font-bold text-white shadow-[3px_3px_0_#1c1917] transition-transform hover:-translate-y-0.5"
                    >
                        <Bell className="size-4" /> Notification settings
                    </Link>
                </div>

                {all.length === 0 ? (
                    <div className="mt-10 max-w-md">
                        <CaveatNote>
                            Nothing here yet. Dump a debrief after your next
                            lecture — paste your notes and the AI pulls out
                            the nuggets worth remembering and the things you
                            said you'd chase. They all collect here for
                            revision.
                        </CaveatNote>
                    </div>
                ) : (
                    <DndContext sensors={sensors} onDragEnd={onDragEnd}>
                        <Zone
                            id="zone-open"
                            label="Nuggets & actions"
                            hint="Tick when it's sunk in — ticked items stop appearing in digests and the morning gem. Click a card for where it came from."
                        >
                            <Wall
                                items={[...openNuggets, ...openActions]}
                                onTick={(w) => patchItem(w, { done: true })}
                                onOpen={(w) => setOpenActivityId(w.activity.id)}
                            />
                        </Zone>

                        <CompletedTable
                            items={done}
                            onRestore={(w) => patchItem(w, { done: false })}
                            onOpen={(w) => setOpenActivityId(w.activity.id)}
                        />
                    </DndContext>
                )}
            </div>

            {openActivity && (
                <ActivityTakeawaysDialog
                    activity={openActivity}
                    onClose={() => setOpenActivityId(null)}
                />
            )}
        </>
    );
}

function Zone({
    id,
    label,
    hint,
    children,
}: {
    id: string;
    label: string;
    hint?: string;
    children: React.ReactNode;
}) {
    const { setNodeRef, isOver } = useDroppable({ id });

    return (
        <div
            ref={setNodeRef}
            className={cn(
                'mt-8 rounded-[14px] p-1 transition-colors',
                isOver && 'bg-brand-tint outline-2 outline-dashed outline-brand',
            )}
        >
            <div className="flex items-baseline gap-3">
                <span className="text-[11px] font-bold tracking-wide text-stone-400 uppercase">
                    {label}
                </span>
                {hint && (
                    <span className="hidden text-[11.5px] text-stone-400 sm:inline">
                        {hint}
                    </span>
                )}
            </div>
            {children}
        </div>
    );
}

function Wall({
    items,
    onTick,
    onOpen,
}: {
    items: WallItem[];
    onTick: (w: WallItem) => void;
    onOpen: (w: WallItem) => void;
}) {
    if (items.length === 0) {
        return (
            <p className="mt-3 text-[13px] text-stone-400">
                All ticked off — restore any from Completed below.
            </p>
        );
    }

    return (
        <div className="mt-3 grid grid-cols-1 items-start gap-4 py-1 sm:grid-cols-2 lg:grid-cols-3">
            {items.map((w, index) => (
                <WallCard
                    key={dragId(w)}
                    w={w}
                    tilt={index % 3 === 0 ? '-0.5deg' : index % 3 === 1 ? '0.45deg' : '-0.2deg'}
                    onTick={() => onTick(w)}
                    onOpen={() => onOpen(w)}
                />
            ))}
        </div>
    );
}

function WallCard({
    w,
    tilt,
    onTick,
    onOpen,
}: {
    w: WallItem;
    tilt: string;
    onTick: () => void;
    onOpen: () => void;
}) {
    const { setNodeRef, attributes, listeners, transform, isDragging } =
        useDraggable({ id: dragId(w) });

    return (
        <div
            ref={setNodeRef}
            {...attributes}
            {...listeners}
            style={{
                rotate: isDragging ? '-1.5deg' : tilt,
                translate: transform
                    ? `${transform.x}px ${transform.y}px`
                    : undefined,
            }}
            className={cn(
                'cursor-grab rounded-[10px] border-2 bg-white p-3 shadow-[3px_3px_0_rgba(28,25,23,.85)]',
                w.kind === 'action' ? 'border-brand' : 'border-ink',
                isDragging
                    ? // No transitions while dragging — easing each pointer
                      // update is what made the card lag and stick.
                      'relative z-10 opacity-70'
                    : 'transition-[translate,box-shadow] duration-150 hover:-translate-y-0.5 hover:shadow-[4px_4px_0_rgba(28,25,23,.85)]',
            )}
        >
            <div className="flex items-start gap-2.5">
                <Checkbox
                    checked={false}
                    onCheckedChange={onTick}
                    onPointerDown={(e) => e.stopPropagation()}
                    title="Got it — stop reminding me"
                    className="mt-0.5"
                />
                <button
                    type="button"
                    onClick={onOpen}
                    className="cursor-pointer text-left text-[13.5px] leading-snug"
                >
                    {w.item.text}
                </button>
            </div>
        </div>
    );
}

function CompletedTable({
    items,
    onRestore,
    onOpen,
}: {
    items: WallItem[];
    onRestore: (w: WallItem) => void;
    onOpen: (w: WallItem) => void;
}) {
    const { setNodeRef, isOver } = useDroppable({ id: 'zone-completed' });

    return (
        <div
            ref={setNodeRef}
            className={cn(
                'mt-10 rounded-[14px] border-2 border-dashed border-stone-300 p-4 transition-colors',
                isOver && 'border-brand bg-brand-tint',
            )}
        >
            <div className="flex items-baseline gap-3">
                <span className="text-[11px] font-bold tracking-wide text-stone-400 uppercase">
                    ✓ Completed ({items.length})
                </span>
                <span className="hidden text-[11.5px] text-stone-400 sm:inline">
                    Drop a card here to mark it done — restore any time.
                </span>
            </div>
            {items.length > 0 && (
                <table className="mt-2 w-full table-fixed text-[12.5px]">
                    <tbody>
                        {items.map((w) => (
                            <tr
                                key={dragId(w)}
                                className="border-t border-stone-200 first:border-t-0"
                            >
                                <td className="truncate py-1.5 pr-3">
                                    <button
                                        type="button"
                                        onClick={() => onOpen(w)}
                                        title="View this activity's takeaways"
                                        className="max-w-full cursor-pointer truncate text-left text-ink hover:underline"
                                    >
                                        {w.item.text}
                                    </button>
                                </td>
                                <td className="w-20 py-1.5">
                                    <button
                                        type="button"
                                        onClick={() => onRestore(w)}
                                        className="flex cursor-pointer items-center gap-0.5 text-[11.5px] font-bold whitespace-nowrap text-brand-dark hover:underline"
                                    >
                                        <ArrowUp className="size-3" /> restore
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </div>
    );
}

/** One activity's takeaways, flippable to the original pasted notes. */
function ActivityTakeawaysDialog({
    activity,
    onClose,
}: {
    activity: TakeawayActivity;
    onClose: () => void;
}) {
    const [view, setView] = useState<'takeaways' | 'notes'>('takeaways');

    const patch = (item: Takeaway, body: { done?: boolean }) =>
        router.patch(
            `/activities/${activity.id}/takeaways/${item.id}`,
            body,
            { preserveScroll: true },
        );

    const destroy = (item: Takeaway) => {
        if (
            confirm(
                'Delete this takeaway? Gone means gone — ticking it done instead keeps it restorable.',
            )
        ) {
            router.delete(
                `/activities/${activity.id}/takeaways/${item.id}`,
                { preserveScroll: true },
            );
        }
    };

    const list = (label: string, items: Takeaway[], accent: boolean) =>
        items.length > 0 && (
            <div className="grid gap-1.5">
                <span className="text-[11px] font-bold tracking-wide text-stone-400 uppercase">
                    {label}
                </span>
                {items.map((item) => (
                    <div
                        key={item.id}
                        className={cn(
                            'flex items-start gap-2 text-sm leading-relaxed',
                            accent && 'border-l-3 border-brand pl-2',
                        )}
                    >
                        <Checkbox
                            checked={item.done}
                            onCheckedChange={() =>
                                patch(item, { done: !item.done })
                            }
                            className="mt-0.5"
                        />
                        <span
                            className={cn(
                                'flex-1',
                                item.done && 'text-stone-400 line-through',
                            )}
                        >
                            {item.text}
                        </span>
                        <button
                            type="button"
                            onClick={() => destroy(item)}
                            title="Delete"
                            className="cursor-pointer p-0.5 text-stone-300 hover:text-red-600"
                        >
                            <Trash2 className="size-3.5" />
                        </button>
                    </div>
                ))}
            </div>
        );

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[88vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 font-display text-xl font-extrabold">
                        <Gem className="size-4.5 text-brand" />
                        {activity.title}
                    </DialogTitle>
                </DialogHeader>

                {activity.has_source_notes && (
                    <div className="inline-flex self-start rounded-full border-2 border-ink p-0.5 text-xs font-semibold">
                        {(['takeaways', 'notes'] as const).map((v) => (
                            <button
                                key={v}
                                type="button"
                                onClick={() => setView(v)}
                                className={cn(
                                    'cursor-pointer rounded-full px-3 py-1',
                                    view === v
                                        ? 'bg-ink text-paper'
                                        : 'text-stone-500 hover:text-ink',
                                )}
                            >
                                {v === 'takeaways'
                                    ? 'AI takeaways'
                                    : 'Your original notes'}
                            </button>
                        ))}
                    </div>
                )}

                {view === 'notes' ? (
                    <p className="text-[13.5px] leading-relaxed whitespace-pre-wrap text-stone-600">
                        {activity.source_notes}
                    </p>
                ) : (
                    <div className="grid gap-5">
                        {list('Nuggets', activity.nuggets, false)}
                        {list('Actions', activity.actions, true)}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
