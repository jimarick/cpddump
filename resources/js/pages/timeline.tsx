import {
    DndContext,
    DragOverlay,
    PointerSensor,
    TouchSensor,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type { DragEndEvent, DragStartEvent } from '@dnd-kit/core';
import { Head, router } from '@inertiajs/react';
import {
    Check,
    Copy,
    Layers,
    Loader2,
    Merge,
    Paperclip,
    PenLine,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { CaveatNote } from '@/components/brand/caveat-note';
import { Chip } from '@/components/brand/chip';
import { Sparkle } from '@/components/brand/sparkle';
import { AttachmentLinks } from '@/components/cpd/attachment-links';
import { EvidenceFormFields } from '@/components/cpd/evidence-form-fields';
import type { EvidenceFormValues } from '@/components/cpd/evidence-form-fields';
import { MergeDialog } from '@/components/cpd/merge/merge-dialog';
import { MergePickerDialog } from '@/components/cpd/merge/merge-picker';
import {
    DragCardOverlay,
    MergeDraggable,
    STACK_DROP_ID,
    StackedPile,
} from '@/components/cpd/merge/stacked-pile';
import { usePendingStack } from '@/components/cpd/merge/use-pending-stack';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    ActivityData,
    InboxStats,
    MergeSeed,
    PeriodData,
    ReferenceData,
    Takeaway,
} from '@/types/cpd';

/** An activity that definitely has a date — the only kind the line can plot. */
type DatedActivity = ActivityData & { starts_on: string };

interface PeriodOption extends PeriodData {
    is_current: boolean;
}

interface Props {
    activities: ActivityData[];
    periods: PeriodOption[];
    period: PeriodOption | null;
    stats: InboxStats;
    legend: { slug: string; name: string; color: string }[];
    reference: ReferenceData;
}

export default function Timeline({
    activities,
    periods,
    period,
    stats,
    legend,
    reference,
}: Props) {
    const [hovered, setHovered] = useState<DatedActivity | null>(null);
    const [editing, setEditing] = useState<ActivityData | null>(null);
    const [resetting, setResetting] = useState(false);
    const [pickingFor, setPickingFor] = useState<ActivityData | null>(null);
    const [mergeSeed, setMergeSeed] = useState<MergeSeed | null>(null);
    const [draggingId, setDraggingId] = useState<number | null>(null);
    const [overTarget, setOverTarget] = useState(false);
    const { stack, start, add, remove: unstack, clear } = usePendingStack();

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
        useSensor(TouchSensor, {
            activationConstraint: { delay: 250, tolerance: 5 },
        }),
    );

    const stackItems = stack
        .map((id) => activities.find((a) => a.id === id))
        .filter((a): a is ActivityData => a !== undefined);
    const stackActive = stackItems.length >= 2;
    const draggingActivity =
        draggingId === null
            ? null
            : (activities.find((a) => a.id === draggingId) ?? null);

    // At most one merged entry per stack — it becomes the absorb target.
    const stackedParents = stackItems.filter(
        (a) => (a.merged_from?.length ?? 0) > 0,
    );
    const mergeDisabledReason =
        stackedParents.length > 1
            ? "Two merged entries can't merge into each other — split one apart first."
            : undefined;

    const onDragStart = (e: DragStartEvent) =>
        setDraggingId(Number(e.active.id));

    const onDragEnd = (e: DragEndEvent) => {
        setDraggingId(null);
        setOverTarget(false);

        const activeId = Number(e.active.id);
        const overId = e.over?.id;

        if (overId === undefined) {
            return;
        }

        if (overId === STACK_DROP_ID) {
            add(activeId);
        } else if (!stackActive && Number(overId) !== activeId) {
            start(Number(overId), activeId);
        }
    };

    const mergeStack = () => {
        const target = stackedParents.length === 1 ? stackedParents[0] : null;

        setMergeSeed({
            activity_ids: stackItems
                .filter((a) => a.id !== target?.id)
                .map((a) => a.id),
            inbox_item_ids: [],
            into_activity_id: target?.id ?? null,
        });
    };

    const dated = useMemo(
        () =>
            activities.filter((a): a is DatedActivity => a.starts_on !== null),
        [activities],
    );

    const positioned = useMemo(() => {
        if (!period) {
            return [];
        }

        const start = new Date(period.starts_on).getTime();
        const end = new Date(period.ends_on).getTime();
        const span = Math.max(end - start, 1);

        const items = dated.map((a) => ({
            activity: a,
            pct: Math.min(
                96,
                Math.max(
                    1,
                    ((new Date(a.starts_on).getTime() - start) / span) * 95 +
                        1.5,
                ),
            ),
            lane: 0,
        }));

        // Stack near-coincident dots into lanes so nothing overlaps.
        const sorted = [...items].sort((x, y) => x.pct - y.pct);
        const laneEnds: number[] = [];

        for (const item of sorted) {
            let lane = 0;

            while (lane < laneEnds.length && item.pct - laneEnds[lane] < 2.2) {
                lane++;
            }

            item.lane = lane;
            laneEnds[lane] = item.pct;
        }

        return items;
    }, [dated, period]);

    const maxLane = positioned.reduce((max, i) => Math.max(max, i.lane), 0);

    const months = useMemo(() => {
        if (!period) {
            return [];
        }

        const start = new Date(period.starts_on);
        const end = new Date(period.ends_on);
        const total = end.getTime() - start.getTime();
        const labels: { label: string; pct: number }[] = [];
        const cursor = new Date(start.getFullYear(), start.getMonth(), 1);

        while (cursor <= end) {
            const pct =
                ((cursor.getTime() - start.getTime()) / total) * 95 + 1.5;

            if (pct >= 0) {
                labels.push({
                    label: cursor.toLocaleDateString('en-GB', {
                        month: 'short',
                    }),
                    pct: Math.max(pct, 1),
                });
            }

            cursor.setMonth(cursor.getMonth() + 1);
        }

        return labels;
    }, [period]);

    const thinnestDomain = stats.gaps.domains.length
        ? [...stats.gaps.domains].sort((a, b) => a.count - b.count)[0]
        : null;

    const totalPoints = activities.reduce((sum, a) => sum + a.cpd_points, 0);

    return (
        <>
            <Head title="Timeline" />

            <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="font-display text-[32px] leading-none font-extrabold tracking-[-0.03em]">
                        Timeline
                    </h1>
                    <p className="mt-1 text-[12.5px] font-semibold text-stone-500">
                        {stats.activities} activities · {stats.points} CPD
                        points
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <Select
                        value={period ? String(period.id) : undefined}
                        onValueChange={(v) =>
                            router.get(
                                '/timeline',
                                { period: v },
                                { preserveState: false },
                            )
                        }
                    >
                        <SelectTrigger className="w-[170px] border-2 border-ink bg-white font-semibold">
                            <SelectValue placeholder="Appraisal year" />
                        </SelectTrigger>
                        <SelectContent>
                            {periods.map((p) => (
                                <SelectItem key={p.id} value={String(p.id)}>
                                    {p.label}
                                    {p.is_current ? ' (current)' : ''}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {period?.is_current && (
                        <Button
                            variant="outline"
                            className="border-2 border-ink font-semibold"
                            onClick={() => setResetting(true)}
                        >
                            Appraisal done?
                        </Button>
                    )}
                </div>
            </div>

            <GapStrip stats={stats} />

            {activities.length === 0 ? (
                <div className="rounded-[14px] border-2 border-dashed border-stone-400 bg-white px-6 py-14 text-center">
                    <h2 className="font-display text-2xl font-extrabold">
                        Nothing on the line yet
                    </h2>
                    <p className="mx-auto mt-1 max-w-sm text-sm text-stone-500">
                        Approve items from your inbox and they'll appear here,
                        dotted across your appraisal year.
                    </p>
                    <CaveatNote rotate={-1.5} className="mt-3">
                        a year of work, on one line
                    </CaveatNote>
                </div>
            ) : (
                <>
                    {/* Desktop: the line */}
                    <div className="relative hidden rotate-[0.3deg] rounded-[14px] border-2 border-ink bg-white px-8 pt-8 pb-6 shadow-[6px_6px_0_rgba(28,25,23,.12)] md:block">
                        <div
                            className="relative"
                            style={{ height: 90 + maxLane * 22 }}
                        >
                            <div
                                className="absolute right-0 left-0 border-t-2 border-dashed border-ink/25"
                                style={{ top: 40 + maxLane * 11 }}
                            />
                            {positioned.map(({ activity, pct, lane }) => (
                                <button
                                    key={activity.id}
                                    type="button"
                                    onMouseEnter={() => setHovered(activity)}
                                    onMouseLeave={() => setHovered(null)}
                                    onClick={() => setEditing(activity)}
                                    className="absolute size-4 cursor-pointer rounded-full border-2 border-ink transition-transform hover:scale-125"
                                    style={{
                                        left: `${pct}%`,
                                        top: 32 + maxLane * 11 - lane * 20,
                                        backgroundColor: activity.type.color,
                                        boxShadow:
                                            hovered?.id === activity.id
                                                ? `0 0 0 5px ${activity.type.color}33`
                                                : undefined,
                                    }}
                                />
                            ))}
                            {months.map((m) => (
                                <div
                                    key={m.label + m.pct}
                                    className="absolute text-[11px] font-semibold text-stone-500"
                                    style={{
                                        left: `${m.pct}%`,
                                        top: 62 + maxLane * 11,
                                    }}
                                >
                                    {m.label}
                                </div>
                            ))}

                            {hovered && (
                                <HoverCard
                                    activity={hovered}
                                    pct={
                                        positioned.find(
                                            (p) => p.activity.id === hovered.id,
                                        )?.pct ?? 50
                                    }
                                />
                            )}
                        </div>

                        <div className="flex flex-wrap justify-center gap-x-5 gap-y-2 border-t border-ink/8 pt-4 text-[11.5px] font-semibold text-stone-600">
                            {legend.map((t) => (
                                <span
                                    key={t.slug}
                                    className="flex items-center gap-1.5"
                                >
                                    <span
                                        className="size-2.5 rounded-full"
                                        style={{ backgroundColor: t.color }}
                                    />
                                    {t.name}
                                </span>
                            ))}
                        </div>
                    </div>

                    <p className="mt-4 hidden text-center text-[13px] text-pretty text-stone-500 md:block">
                        {thinnestDomain && thinnestDomain.count === 0
                            ? `${(thinnestDomain.code ?? '').replace('D', 'Domain ')} (${thinnestDomain.name}) has no evidence yet this year.`
                            : 'Appraisal done? Reset the window — old years stay safe under the year switcher.'}
                    </p>

                    {/* The activities themselves, newest first */}
                    <div className="mt-8">
                        <div className="mb-3 flex items-end justify-between">
                            <h2 className="font-display text-xl font-extrabold">
                                Activities
                            </h2>
                            <span className="text-[12px] font-semibold text-stone-500">
                                {activities.length} approved · {totalPoints} CPD
                                points
                            </span>
                        </div>

        <DndContext
            sensors={sensors}
            onDragStart={onDragStart}
            onDragOver={(e) =>
                setOverTarget(
                    e.over !== null && e.over.id !== e.active.id,
                )
            }
            onDragEnd={onDragEnd}
            onDragCancel={() => {
                setDraggingId(null);
                setOverTarget(false);
            }}
        >
                        <div
                            className={`rounded-[14px] border-2 border-ink bg-white shadow-[6px_6px_0_rgba(28,25,23,.12)] ${
                                stackActive ? '' : 'overflow-hidden'
                            }`}
                        >
                            {activities.map((activity, i) => {
                                if (stackActive) {
                                    if (activity.id === stackItems[0].id) {
                                        return (
                                            <div
                                                key="pending-pile"
                                                className="px-4 md:px-6"
                                            >
                                                <StackedPile
                                                    cards={stackItems.map(
                                                        (member) => ({
                                                            id: member.id,
                                                            title: member.title,
                                                            meta: `${member.starts_on ?? '—'} · ${member.cpd_points} pts${(member.merged_from?.length ?? 0) > 0 ? ' · merged entry' : ''}`,
                                                            accent: member
                                                                .type.color,
                                                        }),
                                                    )}
                                                    onRemove={unstack}
                                                    onClear={clear}
                                                    onMerge={mergeStack}
                                                    mergeDisabledReason={
                                                        mergeDisabledReason
                                                    }
                                                />
                                            </div>
                                        );
                                    }

                                    if (stack.includes(activity.id)) {
                                        return null;
                                    }
                                }

                                return (
                                    <MergeDraggable
                                        key={activity.id}
                                        id={activity.id}
                                        dragDisabled={false}
                                        dropDisabled={
                                            stackActive ||
                                            draggingId === activity.id
                                        }
                                    >
                                <button
                                    type="button"
                                    onClick={() => setEditing(activity)}
                                    className={`flex w-full cursor-pointer items-center gap-3 px-4 py-3 text-left hover:bg-[#fffbf8] md:px-5 ${
                                        i === activities.length - 1
                                            ? ''
                                            : 'border-b border-ink/7'
                                    }`}
                                >
                                    <span
                                        className="size-3 shrink-0 rounded-full border-[1.5px] border-ink"
                                        style={{
                                            backgroundColor:
                                                activity.type.color,
                                        }}
                                        title={activity.type.name}
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-[13.5px] font-semibold">
                                            {activity.title}
                                        </span>
                                        <span className="block truncate text-xs text-stone-500">
                                            {activity.type.name}
                                            {activity.organisation
                                                ? ` · ${activity.organisation}`
                                                : ''}
                                            {activity.domains.length > 0 &&
                                                ` · ${activity.domains.map((d) => d.code).join(', ')}`}
                                        </span>
                                    </span>
                                    {activity.attachments.length > 0 && (
                                        <Paperclip className="size-3.5 shrink-0 text-stone-400" />
                                    )}
                                    {(activity.merged_from?.length ?? 0) >
                                        0 && (
                                        <>
                                            <Layers
                                                className="size-3.5 shrink-0 text-stone-400"
                                                aria-label={`Merged from ${activity.merged_from!.length} entries`}
                                            />
                                            <Chip
                                                variant="dashed"
                                                className="hidden shrink-0 sm:inline-block"
                                            >
                                                merged ×
                                                {activity.merged_from!.length}
                                            </Chip>
                                        </>
                                    )}
                                    <span className="hidden text-xs whitespace-nowrap text-stone-500 sm:inline">
                                        {activity.starts_on ?? '—'}
                                    </span>
                                    <span className="rounded-full bg-brand-tint px-2 py-0.5 text-[10.5px] font-semibold whitespace-nowrap text-brand-dark">
                                        {activity.cpd_points} pts
                                    </span>
                                </button>
                                    </MergeDraggable>
                                );
                            })}
                        </div>
                        <DragOverlay dropAnimation={null}>
                            {draggingActivity && (
                                <DragCardOverlay overTarget={overTarget}>
                                <div className="flex w-full min-w-0 items-center gap-3 rounded-[12px] border-2 border-ink bg-white px-4 py-3">
                                    <span
                                        className="size-3 shrink-0 rounded-full border-[1.5px] border-ink"
                                        style={{
                                            backgroundColor:
                                                draggingActivity.type.color,
                                        }}
                                    />
                                    <span className="min-w-0 flex-1 truncate text-[13.5px] font-semibold">
                                        {draggingActivity.title}
                                    </span>
                                    <span className="rounded-full bg-brand-tint px-2 py-0.5 text-[10.5px] font-semibold whitespace-nowrap text-brand-dark">
                                        {draggingActivity.cpd_points} pts
                                    </span>
                                </div>
                                </DragCardOverlay>
                            )}
                        </DragOverlay>
        </DndContext>
                    </div>
                </>
            )}

            {editing && (
                <EditActivityDialog
                    key={editing.id}
                    // Prefer the live props copy so takeaway ticks made
                    // inside the dialog reflect immediately after reload.
                    activity={
                        activities.find((a) => a.id === editing.id) ?? editing
                    }
                    reference={reference}
                    onClose={() => setEditing(null)}
                    onMergeWith={() => {
                        setPickingFor(editing);
                        setEditing(null);
                    }}
                />
            )}

            {pickingFor && (
                <MergePickerDialog
                    baseLabel={pickingFor.title}
                    baseIsMerged={(pickingFor.merged_from?.length ?? 0) > 0}
                    exclude={{ activityIds: [pickingFor.id], itemIds: [] }}
                    periodId={period?.id}
                    onClose={() => setPickingFor(null)}
                    onConfirm={(selection) => {
                        const baseIsMerged =
                            (pickingFor.merged_from?.length ?? 0) > 0;

                        setMergeSeed(
                            baseIsMerged
                                ? {
                                      ...selection,
                                      into_activity_id: pickingFor.id,
                                  }
                                : {
                                      ...selection,
                                      activity_ids: [
                                          pickingFor.id,
                                          ...selection.activity_ids,
                                      ],
                                  },
                        );
                        setPickingFor(null);
                    }}
                />
            )}

            {mergeSeed && (
                <MergeDialog
                    seed={mergeSeed}
                    reference={reference}
                    onClose={() => setMergeSeed(null)}
                />
            )}

            <Dialog open={resetting} onOpenChange={setResetting}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle className="font-display text-2xl font-extrabold">
                            Start a new appraisal year?
                        </DialogTitle>
                    </DialogHeader>
                    <p className="text-sm text-pretty text-stone-600">
                        This closes <strong>{period?.label}</strong> and opens
                        the next year as your current window. Nothing is deleted
                        — this year stays exactly as it is, one click away in
                        the year switcher.
                    </p>
                    <div className="flex gap-2">
                        <Button
                            className="border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
                            onClick={() =>
                                router.post(
                                    '/timeline/reset',
                                    {},
                                    { onSuccess: () => setResetting(false) },
                                )
                            }
                        >
                            Start new year
                        </Button>
                        <Button
                            variant="outline"
                            className="border-2 border-ink"
                            onClick={() => setResetting(false)}
                        >
                            Cancel
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}

function HoverCard({
    activity,
    pct,
}: {
    activity: DatedActivity;
    pct: number;
}) {
    const alignRight = pct > 60;

    return (
        <div
            className="pointer-events-none absolute z-10 w-[240px] -rotate-[1.5deg] rounded-[10px] bg-ink px-4 py-3 text-paper shadow-[4px_4px_0_rgba(28,25,23,.2)]"
            style={{
                left: alignRight ? undefined : `${Math.max(pct - 4, 0)}%`,
                right: alignRight ? `${Math.max(96 - pct - 4, 0)}%` : undefined,
                top: -66,
            }}
        >
            <div className="truncate text-[13px] font-bold">
                {activity.title}
            </div>
            <div className="mt-[3px] text-[11px] text-stone-400">
                {new Date(activity.starts_on).toLocaleDateString('en-GB', {
                    day: 'numeric',
                    month: 'short',
                })}{' '}
                · {activity.cpd_points} CPD pts
                {activity.domains.length > 0 &&
                    ` · ${activity.domains.map((d) => d.code).join(', ')}`}
            </div>
            {activity.projects.length > 0 && (
                <div className="mt-0.5 truncate text-[11px] text-brand">
                    linked: {activity.projects.map((p) => p.title).join(', ')}
                </div>
            )}
        </div>
    );
}

function GapStrip({ stats }: { stats: InboxStats }) {
    const gaps = stats.gaps;
    const missing = [
        ...gaps.domains
            .filter((d) => d.count === 0)
            .map((d) => (d.code ?? '').replace('D', 'Domain ')),
        ...gaps.categories.filter((c) => c.count === 0).map((c) => c.name),
    ];

    if (missing.length === 0 || stats.activities === 0) {
        return null;
    }

    return (
        <div className="mb-5 rounded-[12px] border-[1.5px] border-dashed border-brand/60 bg-brand-pale px-4 py-2.5 text-[13px] text-stone-600">
            <span className="font-bold text-brand-dark">Looking thin:</span>{' '}
            {missing.slice(0, 5).join(' · ')}
            {missing.length > 5 && ` and ${missing.length - 5} more`}
            <span className="text-stone-400">
                {' '}
                — no evidence yet this appraisal year
            </span>
        </div>
    );
}

/** Long-form UK date for the read-only activity view. */
function formatViewDate(date: string): string {
    return new Date(date).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/** A small copy-to-clipboard button with brief "copied" feedback. */
function CopyButton({ text, label }: { text: string; label: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <button
            type="button"
            aria-label={`Copy ${label}`}
            title="Copy"
            onClick={() => {
                void navigator.clipboard.writeText(text);
                setCopied(true);
                setTimeout(() => setCopied(false), 1500);
            }}
            className={
                copied
                    ? 'cursor-pointer text-green-700'
                    : 'cursor-pointer text-stone-400 hover:text-ink'
            }
        >
            {copied ? (
                <Check className="size-3.5" />
            ) : (
                <Copy className="size-3.5" />
            )}
        </button>
    );
}

/**
 * Nuggets and actions on the activity view, tickable in place — done means
 * "stop resurfacing this" and is reversible. Ticks hit the per-item
 * takeaways endpoint, so they can't collide with a form edit.
 */
function TakeawaysBlock({ activity }: { activity: ActivityData }) {
    const [generating, setGenerating] = useState(false);

    const nuggets = activity.nuggets ?? [];
    const actions = activity.actions ?? [];
    const empty = nuggets.length === 0 && actions.length === 0;

    const generate = () => {
        setGenerating(true);
        router.post(
            `/activities/${activity.id}/takeaways/generate`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setGenerating(false),
            },
        );
    };

    const toggle = (item: Takeaway) =>
        router.patch(
            `/activities/${activity.id}/takeaways/${item.id}`,
            { done: !item.done },
            { preserveScroll: true, preserveState: true },
        );

    const row = (item: Takeaway, accent: boolean) => (
        <label
            key={item.id}
            className={`flex cursor-pointer items-start gap-2 text-sm leading-relaxed ${accent ? 'border-l-3 border-brand pl-2' : ''}`}
        >
            <Checkbox
                checked={item.done}
                onCheckedChange={() => toggle(item)}
                className="mt-0.5"
            />
            <span
                className={
                    item.done ? 'text-stone-400 line-through' : 'text-stone-700'
                }
            >
                {item.text}
            </span>
        </label>
    );

    return (
        <div className="grid gap-3">
            {empty && (
                <div className="flex flex-wrap items-center gap-3 rounded-[10px] border border-dashed border-stone-400 px-3.5 py-2.5">
                    <Button
                        size="sm"
                        onClick={generate}
                        disabled={generating}
                        className="border-2 border-ink font-bold shadow-[2px_2px_0_#1c1917]"
                    >
                        {generating ? (
                            <Loader2 className="size-3.5 animate-spin" />
                        ) : (
                            <Sparkle size={12} />
                        )}{' '}
                        Generate takeaways
                    </Button>
                    <span className="min-w-0 flex-1 text-[12.5px] text-stone-500">
                        Pulls nuggets and actions from this entry onto your
                        Takeaways wall — fed back as morning gems and weekly
                        recaps.
                    </span>
                </div>
            )}
            {nuggets.length > 0 && (
                <div className="grid gap-1.5">
                    <span className="text-sm font-bold">Nuggets</span>
                    {nuggets.map((n) => row(n, false))}
                </div>
            )}
            {actions.length > 0 && (
                <div className="grid gap-1.5">
                    <span className="text-sm font-bold">Actions</span>
                    {actions.map((a) => row(a, true))}
                </div>
            )}
        </div>
    );
}

function EditActivityDialog({
    activity,
    reference,
    onClose,
    onMergeWith,
}: {
    activity: ActivityData;
    reference: ReferenceData;
    onClose: () => void;
    onMergeWith: () => void;
}) {
    const initialValues = (): EvidenceFormValues => ({
        title: activity.title,
        activity_type_slug: activity.type.slug,
        starts_on: activity.starts_on ?? '',
        ends_on: activity.ends_on ?? '',
        organisation: activity.organisation ?? '',
        cpd_points: activity.cpd_points,
        summary: activity.details ?? '',
        // Read-only here: per-item takeaway edits go through the takeaways
        // endpoints, and activities.update ignores these keys.
        nuggets: activity.nuggets ?? [],
        actions: activity.actions ?? [],
        source_notes: activity.source_notes ?? '',
        selected_takeaway_ids: [],
        reflection: activity.reflection ?? {},
        category_slugs: activity.categories.map((c) => c.slug),
        domain_codes: activity.domains.map((d) => d.code),
        attribute_codes: activity.attribute_codes,
        project_ids: activity.projects.map((p) => p.id),
    });

    const [mode, setMode] = useState<'view' | 'edit'>('view');
    const [viewTab, setViewTab] = useState<'ai' | 'notes'>('ai');
    const [values, setValues] = useState<EvidenceFormValues>(initialValues);

    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [confirmingSplit, setConfirmingSplit] = useState(false);

    const mergedFrom = activity.merged_from ?? [];
    const isMergedParent = mergedFrom.length > 0;

    const split = () => {
        setProcessing(true);
        router.post(
            `/activities/${activity.id}/unmerge`,
            {},
            {
                onSuccess: onClose,
                onFinish: () => setProcessing(false),
            },
        );
    };

    const save = () => {
        setProcessing(true);
        router.put(
            `/activities/${activity.id}`,
            {
                ...values,
                cpd_points: Number(values.cpd_points),
                starts_on: values.starts_on || null,
                ends_on: values.ends_on || null,
                details: values.summary,
            },
            {
                onSuccess: onClose,
                onError: (errs) => setErrors(errs as Record<string, string>),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const remove = () => {
        setProcessing(true);
        router.delete(`/activities/${activity.id}`, {
            onSuccess: onClose,
            onFinish: () => setProcessing(false),
        });
    };

    const reflectionBlocks = reference.reflectionPrompts
        .map((prompt) => ({
            key: prompt.key,
            label: prompt.label,
            text: activity.reflection?.[prompt.key] ?? '',
        }))
        .filter((block) => block.text.trim() !== '');

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[92vh] w-[min(100vw-2rem,52rem)] overflow-y-auto sm:max-w-3xl">
                {mode === 'view' ? (
                    <>
                        <div className="flex items-start justify-between gap-3">
                            <DialogHeader>
                                <DialogTitle className="font-display text-2xl font-extrabold">
                                    {activity.title}
                                </DialogTitle>
                            </DialogHeader>
                            <Button
                                size="sm"
                                onClick={() => setMode('edit')}
                                className="mr-6 shrink-0 border-2 border-ink font-bold shadow-[2px_2px_0_#1c1917]"
                            >
                                <PenLine className="size-3.5" /> Edit
                            </Button>
                        </div>

                        {activity.starts_on && (
                            <p className="-mt-2 text-[13px] text-stone-500">
                                {formatViewDate(activity.starts_on)}
                                {activity.ends_on &&
                                    activity.ends_on !== activity.starts_on &&
                                    ` – ${formatViewDate(activity.ends_on)}`}
                            </p>
                        )}

                        <AttachmentLinks
                            attachments={activity.attachments}
                            onDelete={(attachment) => {
                                if (
                                    confirm(
                                        `Delete “${attachment.name}”? The file is permanently deleted — your written entry is kept.`,
                                    )
                                ) {
                                    router.delete(
                                        `/attachments/${attachment.id}`,
                                        { preserveScroll: true },
                                    );
                                }
                            }}
                        />

                        <div className="flex flex-wrap items-center gap-1.5">
                            <span className="flex items-center gap-1.5 rounded-full border-[1.5px] border-ink bg-white px-2.5 py-0.5 text-xs font-semibold">
                                <span
                                    className="size-2 rounded-full"
                                    style={{
                                        backgroundColor: activity.type.color,
                                    }}
                                />
                                {activity.type.name}
                            </span>
                            <span className="rounded-full border-[1.5px] border-ink bg-brand-tint px-2.5 py-0.5 text-xs font-semibold text-brand-dark">
                                {activity.cpd_points} points
                            </span>
                            {activity.categories.map((c) => (
                                <span
                                    key={c.slug}
                                    className="rounded-full border border-stone-300 px-2.5 py-0.5 text-xs text-stone-600"
                                >
                                    {c.name}
                                </span>
                            ))}
                            {activity.domains.map((d) => (
                                <span
                                    key={d.code}
                                    className="rounded-full border border-stone-300 px-2.5 py-0.5 text-xs text-stone-600"
                                >
                                    {d.code.replace('D', 'Domain ')}
                                </span>
                            ))}
                        </div>

                        {activity.projects.length > 0 && (
                            <div className="flex flex-wrap items-center gap-1.5">
                                <span className="text-[11px] font-bold tracking-wide text-stone-400 uppercase">
                                    Projects:
                                </span>
                                {activity.projects.map((p) => (
                                    <span
                                        key={p.id}
                                        className="rounded-full border border-dashed border-stone-400 px-2.5 py-0.5 text-xs text-stone-600"
                                    >
                                        {p.title}
                                    </span>
                                ))}
                            </div>
                        )}


                        {isMergedParent && (
                            <div className="rounded-[10px] border border-dashed border-stone-400 px-4 py-2.5 text-[13px] text-stone-600">
                                <Layers className="mr-1.5 inline size-3.5 text-stone-400" />
                                <span className="font-semibold">
                                    Merged from:
                                </span>{' '}
                                {mergedFrom.map((c) => c.title).join(' · ')}{' '}
                                <button
                                    type="button"
                                    onClick={() => setConfirmingSplit(true)}
                                    className="cursor-pointer font-semibold text-ink underline decoration-dashed underline-offset-4 hover:text-brand-dark"
                                >
                                    Split apart
                                </button>
                            </div>
                        )}

                        {!isMergedParent && activity.formerly_merged && (
                            <p className="text-[12px] text-stone-400">
                                <Layers className="mr-1 inline size-3" />
                                This entry was previously part of a merged
                                entry
                                {activity.merge_unreviewed
                                    ? ' — it was created from the AI analysis during that merge, so give its details a once-over'
                                    : ''}
                                .
                            </p>
                        )}

                        {activity.source_notes && (
                            <div className="inline-flex self-start rounded-full border-2 border-ink p-0.5 text-xs font-semibold">
                                {(['ai', 'notes'] as const).map((v) => (
                                    <button
                                        key={v}
                                        type="button"
                                        onClick={() => setViewTab(v)}
                                        className={`cursor-pointer rounded-full px-3 py-1 ${
                                            viewTab === v
                                                ? 'bg-ink text-paper'
                                                : 'text-stone-500 hover:text-ink'
                                        }`}
                                    >
                                        {v === 'ai'
                                            ? 'AI write-up'
                                            : 'My notes'}
                                    </button>
                                ))}
                            </div>
                        )}

                        {viewTab === 'notes' && activity.source_notes ? (
                            <p className="text-sm leading-relaxed whitespace-pre-wrap text-stone-700">
                                {activity.source_notes}
                            </p>
                        ) : (
                            <>
                                {activity.details && (
                                    <div className="grid gap-1">
                                        <div className="flex items-center gap-1.5">
                                            <span className="text-sm font-bold">
                                                Details
                                            </span>
                                            <CopyButton
                                                text={activity.details}
                                                label="details"
                                            />
                                        </div>
                                        <p className="text-sm leading-relaxed whitespace-pre-wrap text-stone-700">
                                            {activity.details}
                                        </p>
                                    </div>
                                )}

                                {reflectionBlocks.map((block) => (
                                    <div
                                        key={block.key}
                                        className="grid gap-1"
                                    >
                                        <div className="flex items-center gap-1.5">
                                            <span className="text-sm font-bold">
                                                {block.label}
                                            </span>
                                            <CopyButton
                                                text={block.text}
                                                label={block.label}
                                            />
                                        </div>
                                        <p className="text-sm leading-relaxed whitespace-pre-wrap text-stone-700">
                                            {block.text}
                                        </p>
                                    </div>
                                ))}
                            </>
                        )}

                        <div className="border-t border-dashed border-stone-300 pt-4">
                            <TakeawaysBlock activity={activity} />
                        </div>

                        <div className="mt-2 flex items-center gap-2 border-t border-dashed border-stone-300 pt-4">
                            <Button
                                variant="ghost"
                                onClick={onMergeWith}
                                disabled={processing}
                                className="text-stone-500"
                            >
                                <Merge className="size-4" /> Merge with…
                            </Button>
                            <Button
                                variant="ghost"
                                onClick={() => setConfirmingDelete(true)}
                                disabled={processing}
                                className="ml-auto text-red-600 hover:text-red-700"
                            >
                                <Trash2 className="size-4" /> Delete
                            </Button>
                        </div>
                    </>
                ) : (
                    <>
                        <DialogHeader>
                            <DialogTitle className="font-display text-2xl font-extrabold">
                                Edit activity
                            </DialogTitle>
                        </DialogHeader>

                        <EvidenceFormFields
                            values={values}
                            onChange={(patch) =>
                                setValues((v) => ({ ...v, ...patch }))
                            }
                            reference={reference}
                            errors={errors}
                        />

                        <div className="border-t border-dashed border-stone-300 pt-4">
                            <TakeawaysBlock activity={activity} />
                        </div>

                        <div className="mt-2 flex items-center gap-2 border-t border-dashed border-stone-300 pt-4">
                            <Button
                                onClick={save}
                                disabled={processing}
                                className="border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
                            >
                                {processing && (
                                    <Loader2 className="size-4 animate-spin" />
                                )}{' '}
                                Save
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setValues(initialValues());
                                    setErrors({});
                                    setMode('view');
                                }}
                                disabled={processing}
                                className="border-2 border-ink"
                            >
                                Cancel
                            </Button>
                        </div>
                    </>
                )}

                {confirmingSplit && (
                    <Dialog
                        open
                        onOpenChange={(o) => !o && setConfirmingSplit(false)}
                    >
                        <DialogContent className="sm:max-w-md">
                            <DialogHeader>
                                <DialogTitle className="font-display text-xl font-extrabold">
                                    Split “{activity.title}” back into{' '}
                                    {mergedFrom.length} activities?
                                </DialogTitle>
                            </DialogHeader>
                            <p className="text-sm text-stone-600">
                                The original entries come back exactly as they
                                were — their own points, dates, reflections and
                                files. Nothing is deleted; only this combined
                                entry goes away.
                            </p>
                            <div className="flex items-center gap-2 pt-1">
                                <Button
                                    onClick={split}
                                    disabled={processing}
                                    className="border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
                                >
                                    {processing && (
                                        <Loader2 className="size-4 animate-spin" />
                                    )}{' '}
                                    Split it
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => setConfirmingSplit(false)}
                                    disabled={processing}
                                    className="border-2 border-ink"
                                >
                                    Keep merged
                                </Button>
                            </div>
                        </DialogContent>
                    </Dialog>
                )}

                {confirmingDelete && (
                    <Dialog
                        open
                        onOpenChange={(o) => !o && setConfirmingDelete(false)}
                    >
                        <DialogContent className="sm:max-w-md">
                            <DialogHeader>
                                <DialogTitle className="font-display text-xl font-extrabold">
                                    Delete “{activity.title}”?
                                </DialogTitle>
                            </DialogHeader>
                            <p className="text-sm text-stone-600">
                                {isMergedParent ? (
                                    <>
                                        This permanently deletes it{' '}
                                        <span className="font-semibold text-ink">
                                            and the {mergedFrom.length} entries
                                            merged into it
                                        </span>
                                        , including their reflections and any
                                        kept files.{' '}
                                    </>
                                ) : (
                                    <>
                                        This permanently deletes it — including
                                        your reflection and any kept files.{' '}
                                    </>
                                )}
                                <span className="font-semibold text-ink">
                                    This cannot be undone.
                                </span>
                            </p>
                            <div className="flex flex-wrap items-center gap-2 pt-1">
                                <Button
                                    onClick={remove}
                                    disabled={processing}
                                    className="border-2 border-ink bg-red-600 font-bold text-white shadow-[3px_3px_0_#1c1917] hover:bg-red-700"
                                >
                                    {processing && (
                                        <Loader2 className="size-4 animate-spin" />
                                    )}{' '}
                                    Delete forever
                                </Button>
                                {isMergedParent && (
                                    <Button
                                        variant="outline"
                                        onClick={split}
                                        disabled={processing}
                                        className="border-2 border-ink font-semibold"
                                    >
                                        Split apart instead
                                    </Button>
                                )}
                                <Button
                                    variant="outline"
                                    onClick={() => setConfirmingDelete(false)}
                                    disabled={processing}
                                    className="border-2 border-ink"
                                >
                                    Keep it
                                </Button>
                            </div>
                        </DialogContent>
                    </Dialog>
                )}
            </DialogContent>
        </Dialog>
    );
}
