import {
    DndContext,
    DragOverlay,
    PointerSensor,
    TouchSensor,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type { DragEndEvent, DragStartEvent } from '@dnd-kit/core';
import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ChevronLeft,
    ClipboardList,
    FileUp,
    Link2,
    Loader2,
    Merge,
    Mic,
    Paperclip,
    PenLine,
    Plus,
    RefreshCw,
    Repeat,
    Trash2,
    X,
} from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useEffect, useMemo, useState } from 'react';
import { CaveatNote } from '@/components/brand/caveat-note';
import { Chip } from '@/components/brand/chip';
import { Sparkle } from '@/components/brand/sparkle';
import { ApproveConfirmDialog } from '@/components/cpd/approve-confirm-dialog';
import { AttachmentLinks } from '@/components/cpd/attachment-links';
import {
    DictatedInput,
    DictatedTextarea,
} from '@/components/cpd/dictated-fields';
import type { EvidenceFormValues } from '@/components/cpd/evidence-form-fields';
import {
    EvidenceWizard,
    stepForErrors,
    WIZARD_STEP_COUNT,
} from '@/components/cpd/evidence-wizard';
import { InboxDoodles } from '@/components/cpd/inbox-doodles';
import { MergeDialog } from '@/components/cpd/merge/merge-dialog';
import { MergePickerDialog } from '@/components/cpd/merge/merge-picker';
import {
    DragCardOverlay,
    MergeDraggable,
    STACK_DROP_ID,
    StackedPile,
} from '@/components/cpd/merge/stacked-pile';
import { usePendingStack } from '@/components/cpd/merge/use-pending-stack';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type {
    InboxItemData,
    MergeSeed,
    PeriodData,
    RecurrenceData,
    ReferenceData,
} from '@/types/cpd';

interface Props {
    items: InboxItemData[];
    period: PeriodData | null;
    reference: ReferenceData;
    dumpAddress: string | null;
    recurrences: RecurrenceData[];
    attachmentRetention: 'ask' | 'always' | 'never';
}

export default function Inbox({
    items,
    period,
    reference,
    dumpAddress,
    recurrences,
    attachmentRetention,
}: Props) {
    const [reviewing, setReviewing] = useState<InboxItemData | null>(null);
    const [pickingFor, setPickingFor] = useState<InboxItemData | null>(null);
    const [mergeSeed, setMergeSeed] = useState<MergeSeed | null>(null);
    const [draggingId, setDraggingId] = useState<number | null>(null);
    const [overTarget, setOverTarget] = useState(false);
    const [relatedHighlight, setRelatedHighlight] = useState<number[] | null>(
        null,
    );
    const { stack, start, add, remove: unstack, clear } = usePendingStack();
    const [adding, setAdding] = useState(false);
    const [addMode, setAddMode] = useState<DumpMode | null>(null);
    const [managing, setManaging] = useState<RecurrenceData | null>(null);
    const [droppedFiles, setDroppedFiles] = useState<File[]>([]);
    const [dragging, setDragging] = useState(false);

    useEffect(() => {
        let depth = 0;
        const hasFiles = (e: DragEvent) =>
            Array.from(e.dataTransfer?.types ?? []).includes('Files');

        const enter = (e: DragEvent) => {
            if (!hasFiles(e)) {
                return;
            }

            depth += 1;
            setDragging(true);
        };
        const over = (e: DragEvent) => {
            if (hasFiles(e)) {
                e.preventDefault();
            }
        };
        const leave = (e: DragEvent) => {
            if (!hasFiles(e)) {
                return;
            }

            depth = Math.max(0, depth - 1);

            if (depth === 0) {
                setDragging(false);
            }
        };
        const drop = (e: DragEvent) => {
            if (!hasFiles(e)) {
                return;
            }

            e.preventDefault();
            depth = 0;
            setDragging(false);
            const files = Array.from(e.dataTransfer?.files ?? []);

            if (files.length > 0) {
                setDroppedFiles(files.slice(0, 5));
                setAdding(true);
            }
        };

        window.addEventListener('dragenter', enter);
        window.addEventListener('dragover', over);
        window.addEventListener('dragleave', leave);
        window.addEventListener('drop', drop);

        return () => {
            window.removeEventListener('dragenter', enter);
            window.removeEventListener('dragover', over);
            window.removeEventListener('dragleave', leave);
            window.removeEventListener('drop', drop);
        };
    }, []);

    const analysing = useMemo(
        () =>
            items.some(
                (i) => i.status === 'pending' || i.status === 'analysing',
            ),
        [items],
    );

    // Pause the poll while a drag is in flight — rows must not be swapped
    // out from under the pointer.
    useEffect(() => {
        if (!analysing || draggingId !== null) {
            return;
        }

        const timer = setInterval(
            () => router.reload({ only: ['items'] }),
            5000,
        );

        return () => clearInterval(timer);
    }, [analysing, draggingId]);

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
        useSensor(TouchSensor, {
            activationConstraint: { delay: 250, tolerance: 5 },
        }),
    );

    // Membership is derived from live props: items approved or binned
    // elsewhere silently fall off; fewer than two survivors = no pile.
    const stackItems = stack
        .map((id) => items.find((i) => i.id === id && i.status === 'ready'))
        .filter((i): i is InboxItemData => i !== undefined);
    const stackActive = stackItems.length >= 2;
    const draggingItem =
        draggingId === null
            ? null
            : (items.find((i) => i.id === draggingId) ?? null);

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

    return (
        <>
            <Head title="Inbox" />

            <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="font-display text-[32px] leading-none font-extrabold tracking-[-0.03em]">
                        Inbox
                    </h1>
                    {period && (
                        <p className="mt-1 text-[12.5px] font-semibold text-stone-500">
                            since{' '}
                            {new Date(period.starts_on).toLocaleDateString(
                                'en-GB',
                                {
                                    day: 'numeric',
                                    month: 'long',
                                    year: 'numeric',
                                },
                            )}
                        </p>
                    )}
                </div>
                <Button
                    onClick={() => setAdding(true)}
                    className="rotate-[-1deg] border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
                >
                    <Plus className="size-4" /> Add something else
                </Button>
            </div>

            {items.length === 0 ? (
                <EmptyState onAdd={() => setAdding(true)} />
            ) : (
                <div className="relative mt-9 flex h-[62vh] min-h-[420px] flex-col rounded-[18px] border-2 border-dashed border-stone-400 bg-white p-4 pt-6 shadow-[inset_3px_4px_10px_rgba(28,25,23,.10)]">
                    <span className="pointer-events-none absolute -top-7 left-7 rotate-[-3deg] font-hand text-[26px] font-semibold text-ink">
                        waiting for your review ↓
                    </span>
                    <InboxDoodles />
                    <DndContext
                        sensors={sensors}
                        onDragStart={onDragStart}
                        onDragOver={(e) =>
                            setOverTarget(
                                e.over !== null &&
                                    e.over.id !== e.active.id,
                            )
                        }
                        onDragEnd={onDragEnd}
                        onDragCancel={() => {
                            setDraggingId(null);
                            setOverTarget(false);
                        }}
                    >
                        <div
                            data-doodle-obstacle
                            className="relative grid min-h-0 min-w-0 flex-1 auto-rows-min grid-cols-[minmax(0,1fr)] gap-2.5 overflow-y-auto px-3 pt-3 pb-3"
                        >
                            {items.map((item, i) => {
                                if (stackActive) {
                                    if (item.id === stackItems[0].id) {
                                        return (
                                            <StackedPile
                                                key="pending-pile"
                                                cards={stackItems.map(
                                                    (member) => ({
                                                        id: member.id,
                                                        title: itemTitle(
                                                            member,
                                                        ),
                                                        meta: `${member.source_label} · ${member.ai_analysis?.cpd_points ?? 0} pts`,
                                                    }),
                                                )}
                                                onRemove={unstack}
                                                onClear={clear}
                                                onMerge={() =>
                                                    setMergeSeed({
                                                        activity_ids: [],
                                                        inbox_item_ids:
                                                            stackItems.map(
                                                                (m) => m.id,
                                                            ),
                                                        into_activity_id:
                                                            null,
                                                    })
                                                }
                                            />
                                        );
                                    }

                                    if (stack.includes(item.id)) {
                                        return null;
                                    }
                                }

                                return (
                                    <MergeDraggable
                                        key={item.id}
                                        id={item.id}
                                        dragDisabled={
                                            item.status !== 'ready'
                                        }
                                        dropDisabled={
                                            stackActive ||
                                            item.status !== 'ready' ||
                                            draggingId === item.id
                                        }
                                    >
                                        <InboxRow
                                            item={item}
                                            index={i}
                                            highlighted={
                                                relatedHighlight?.includes(
                                                    item.id,
                                                ) ?? false
                                            }
                                            onHoverRelated={
                                                setRelatedHighlight
                                            }
                                            onOpen={() =>
                                                item.status === 'ready' ||
                                                item.status === 'failed'
                                                    ? setReviewing(item)
                                                    : undefined
                                            }
                                            onDelete={() =>
                                                router.delete(
                                                    `/inbox/${item.id}`,
                                                    {
                                                        preserveScroll: true,
                                                    },
                                                )
                                            }
                                        />
                                    </MergeDraggable>
                                );
                            })}
                        </div>
                        <DragOverlay dropAnimation={null}>
                            {draggingItem && (
                                <DragCardOverlay overTarget={overTarget}>
                                    <InboxRow
                                        item={draggingItem}
                                        index={0}
                                        onDelete={() => undefined}
                                    />
                                </DragCardOverlay>
                            )}
                        </DragOverlay>
                    </DndContext>
                    <RegularsStrip
                        recurrences={recurrences}
                        onAdd={() => {
                            setAddMode('regular');
                            setAdding(true);
                        }}
                        onManage={setManaging}
                    />
                </div>
            )}

            {dumpAddress && (
                <p className="mt-4 text-center text-[13px] text-stone-500">
                    forward anything to{' '}
                    <button
                        type="button"
                        onClick={() =>
                            navigator.clipboard?.writeText(dumpAddress)
                        }
                        title="Click to copy"
                        className="cursor-pointer rounded bg-brand-tint px-1.5 py-0.5 font-mono text-[11.5px] text-brand-dark"
                    >
                        {dumpAddress}
                    </button>
                </p>
            )}

            {dragging && (
                <div className="pointer-events-none fixed inset-0 z-50 flex items-center justify-center bg-paper/85">
                    <div className="rotate-[-1deg] rounded-[16px] border-[3px] border-dashed border-brand bg-white px-10 py-8 text-center shadow-[6px_6px_0_rgba(28,25,23,.15)]">
                        <FileUp className="mx-auto size-8 text-brand" />
                        <p className="mt-2 font-display text-2xl font-extrabold">
                            Drop it on the pile
                        </p>
                        <p className="text-[13px] text-stone-500">
                            Certificates, PDFs, photos — the AI does the filing.
                        </p>
                    </div>
                </div>
            )}

            {adding && (
                <AddEvidenceDialog
                    initialFiles={droppedFiles}
                    initialMode={addMode}
                    activityTypes={reference.activityTypes}
                    onClose={() => {
                        setAdding(false);
                        setAddMode(null);
                        setDroppedFiles([]);
                    }}
                />
            )}

            {managing && (
                <ManageRecurrenceDialog
                    key={managing.id}
                    recurrence={managing}
                    onClose={() => setManaging(null)}
                />
            )}

            {reviewing && (
                <ReviewDialog
                    key={reviewing.id}
                    item={reviewing}
                    reference={reference}
                    retention={attachmentRetention}
                    onClose={() => setReviewing(null)}
                    onMergeWith={() => {
                        setPickingFor(reviewing);
                        setReviewing(null);
                    }}
                    onMergeInstead={() => {
                        const suggestions = reviewing.merge_suggestions ?? [];
                        const activities = suggestions.filter(
                            (s) => s.kind === 'activity',
                        );
                        const target =
                            activities.find((s) => s.merged) ?? null;

                        setMergeSeed({
                            activity_ids: activities
                                .filter((s) => s.id !== target?.id)
                                .map((s) => s.id),
                            inbox_item_ids: [
                                reviewing.id,
                                ...suggestions
                                    .filter((s) => s.kind === 'inbox')
                                    .map((s) => s.id),
                            ],
                            into_activity_id: target?.id ?? null,
                        });
                        setReviewing(null);
                    }}
                />
            )}

            {pickingFor && (
                <MergePickerDialog
                    baseLabel={itemTitle(pickingFor)}
                    exclude={{ activityIds: [], itemIds: [pickingFor.id] }}
                    onClose={() => setPickingFor(null)}
                    onConfirm={(selection) => {
                        setMergeSeed({
                            ...selection,
                            inbox_item_ids: [
                                pickingFor.id,
                                ...selection.inbox_item_ids,
                            ],
                        });
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
        </>
    );
}

function EmptyState({ onAdd }: { onAdd: () => void }) {
    return (
        <div className="rounded-[14px] border-2 border-dashed border-stone-400 bg-white px-6 py-14 text-center">
            <div className="mx-auto flex size-14 items-center justify-center rounded-full bg-ink">
                <Sparkle size={26} className="text-brand" />
            </div>
            <h2 className="mt-4 font-display text-2xl font-extrabold">
                Nothing in the pile yet
            </h2>
            <p className="mx-auto mt-1 max-w-sm text-sm text-pretty text-stone-500">
                Drop a certificate anywhere on this page, paste a link, or type
                a few words about something you did. The AI does the filing.
            </p>
            <Button
                onClick={onAdd}
                className="mt-5 border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
            >
                <Plus className="size-4" /> Dump your first thing
            </Button>
            <CaveatNote rotate={1.5} className="mt-4">
                approve or bin. that's the job.
            </CaveatNote>
        </div>
    );
}

const ROW_TILTS = ['-0.4deg', '0.3deg', '-0.2deg', '0.45deg', '-0.35deg'];

function itemTitle(item: InboxItemData): string {
    return (
        item.ai_analysis?.title ??
        (item.raw_payload.title as string | undefined) ??
        (item.raw_payload.subject as string | undefined) ??
        (item.raw_payload.url as string | undefined) ??
        'Untitled evidence'
    );
}

function InboxRow({
    item,
    index,
    onOpen,
    onDelete,
    highlighted = false,
    onHoverRelated,
}: {
    item: InboxItemData;
    index: number;
    onOpen?: () => void;
    onDelete: () => void;
    highlighted?: boolean;
    onHoverRelated?: (ids: number[] | null) => void;
}) {
    const title = itemTitle(item);

    const busy = item.status === 'pending' || item.status === 'analysing';
    const failed = item.status === 'failed';
    const warnings = (item.ai_warnings?.pii_flags?.length ?? 0) > 0;
    const relatedIds = [
        ...(item.ai_warnings?.possible_related_inbox_item_ids ?? []),
        ...(item.ai_warnings?.possible_duplicate_inbox_item_ids ?? []),
    ];

    return (
        <div
            onClick={busy ? undefined : onOpen}
            onMouseEnter={
                relatedIds.length > 0 && onHoverRelated
                    ? () => onHoverRelated([item.id, ...relatedIds])
                    : undefined
            }
            onMouseLeave={
                relatedIds.length > 0 && onHoverRelated
                    ? () => onHoverRelated(null)
                    : undefined
            }
            style={{ rotate: ROW_TILTS[index % ROW_TILTS.length] }}
            className={`flex w-full min-w-0 items-center gap-3 rounded-[12px] border-2 border-ink bg-white px-4 py-3 text-left shadow-[3px_3px_0_rgba(28,25,23,.12)] transition-transform md:px-5 ${
                busy
                    ? 'cursor-default opacity-70'
                    : 'cursor-pointer hover:-translate-y-0.5 hover:bg-[#fffbf8]'
            } ${highlighted ? 'ring-2 ring-brand/40' : ''}`}
        >
            <span className="w-[58px] shrink-0 text-[9.5px] font-bold tracking-[0.08em] text-stone-500 uppercase">
                {item.source_label}
            </span>

            <span className="min-w-0 flex-1">
                <span className="block truncate text-[13.5px] font-semibold">
                    {title}
                </span>
                {failed && (
                    <span className="block truncate text-xs text-red-600">
                        {item.failure_reason}
                    </span>
                )}
                {busy &&
                    (item.failure_reason ? (
                        <span className="block truncate text-xs text-amber-700">
                            {item.failure_reason}
                        </span>
                    ) : (
                        <span className="flex items-center gap-1.5 text-xs text-stone-500">
                            <Loader2 className="size-3 animate-spin" /> AI is
                            reading this…
                        </span>
                    ))}
            </span>

            {item.attachments.length > 0 && (
                <Paperclip className="size-3.5 shrink-0 text-stone-400" />
            )}
            {relatedIds.length > 0 && (
                <Link2
                    className="size-3.5 shrink-0 text-brand"
                    aria-label="Possibly related to other evidence"
                />
            )}
            {warnings && (
                <AlertTriangle className="size-4 shrink-0 text-brand" />
            )}

            {item.ai_analysis && (
                <span className="hidden sm:inline">
                    <Chip>
                        {item.ai_analysis.activity_type_slug.replace('_', ' ')}{' '}
                        · {item.ai_analysis.cpd_points} pts
                    </Chip>
                </span>
            )}

            {item.status === 'ready' && (
                <span className="rounded-[7px] border-[1.5px] border-ink bg-white px-2.5 py-1 text-[11.5px] font-bold whitespace-nowrap">
                    Review
                </span>
            )}
            {failed && (
                <span className="flex items-center gap-1 rounded-[7px] border-[1.5px] border-ink bg-white px-2.5 py-1 text-[11.5px] font-bold whitespace-nowrap">
                    <RefreshCw className="size-3" /> Retry
                </span>
            )}

            <button
                type="button"
                title="Bin it"
                aria-label={`Bin ${title}`}
                onClick={(e) => {
                    e.stopPropagation();
                    onDelete();
                }}
                className="shrink-0 cursor-pointer rounded p-1 text-red-500/60 transition-colors hover:bg-red-50 hover:text-red-600"
            >
                <Trash2 className="size-4" />
            </button>
        </div>
    );
}

const FILE_ACCEPT =
    '.pdf,.jpg,.jpeg,.png,.webp,.heic,.gif,.doc,.docx,.ppt,.pptx,.txt';

type DumpMode = 'debrief' | 'files' | 'link' | 'voice' | 'regular';

/** The smaller cards under the Debrief hero. */
const DUMP_MODES: {
    key: DumpMode;
    icon: typeof FileUp;
    title: string;
    blurb: string;
    rotate: string;
}[] = [
    {
        key: 'files',
        icon: FileUp,
        title: 'Dump files',
        blurb: 'Certificates, PDFs, photos',
        rotate: '-0.8deg',
    },
    {
        key: 'link',
        icon: Link2,
        title: 'Paste a link',
        blurb: 'Course page, article, e-learning',
        rotate: '0.6deg',
    },
    {
        key: 'voice',
        icon: Mic,
        title: 'Voice note',
        blurb: 'Talk it out, we type it up',
        rotate: '-0.5deg',
    },
    {
        key: 'regular',
        icon: Repeat,
        title: 'Something regular',
        blurb: 'MDTs, journal clubs, audits',
        rotate: '0.4deg',
    },
];

function AddEvidenceDialog({
    initialFiles,
    initialMode = null,
    activityTypes,
    onClose,
}: {
    initialFiles: File[];
    initialMode?: DumpMode | null;
    activityTypes: ReferenceData['activityTypes'];
    onClose: () => void;
}) {
    const [mode, setMode] = useState<DumpMode | null>(
        initialFiles.length > 0 ? 'files' : initialMode,
    );

    const form = useForm<{
        title: string;
        details: string;
        notes: string;
        occurred_on: string;
        url: string;
        files: File[];
    }>({
        title: '',
        details: '',
        notes: '',
        occurred_on: '',
        url: '',
        files: initialFiles,
    });

    const close = () => {
        form.reset();
        onClose();
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/inbox', { forceFormData: true, onSuccess: close });
    };

    const canSubmit =
        mode === 'files'
            ? form.data.files.length > 0
            : mode === 'link'
              ? form.data.url.trim() !== ''
              : mode === 'voice'
                ? form.data.notes.trim() !== ''
                : form.data.title.trim() !== '' ||
                  form.data.notes.trim() !== '';

    const openDebrief = () => {
        // Date defaults to today; the user corrects it when the lecture
        // wasn't. Notes make it a debrief server-side.
        form.setData('occurred_on', new Date().toISOString().slice(0, 10));
        setMode('debrief');
    };

    return (
        <Dialog open onOpenChange={(o) => !o && close()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2.5 font-display text-2xl font-extrabold">
                        {mode !== null && (
                            <button
                                type="button"
                                onClick={() => setMode(null)}
                                aria-label="Back to choices"
                                className="flex size-7 cursor-pointer items-center justify-center rounded-full border-[1.5px] border-ink bg-white hover:bg-brand-tint"
                            >
                                <ChevronLeft className="size-4" />
                            </button>
                        )}
                        Dump something
                    </DialogTitle>
                </DialogHeader>

                {mode === null ? (
                    <div className="grid gap-3 py-1 sm:grid-cols-2">
                        <button
                            type="button"
                            onClick={openDebrief}
                            style={{ rotate: '-0.4deg' }}
                            className="cursor-pointer rounded-[12px] border-2 border-ink bg-brand-pale px-4 py-6 text-center shadow-[4px_4px_0_rgba(28,25,23,.18)] transition-transform hover:-translate-y-0.5 hover:bg-brand-tint sm:col-span-2"
                        >
                            <ClipboardList className="mx-auto size-7 text-brand" />
                            <span className="mt-2 block text-[16px] font-bold">
                                Debrief
                            </span>
                            <span className="mt-0.5 block text-[12px] leading-snug text-stone-500">
                                A few words or your whole lecture notes — typed,
                                pasted or dictated. The AI files the rest.
                            </span>
                        </button>
                        {DUMP_MODES.map((m) => (
                            <button
                                key={m.key}
                                type="button"
                                onClick={() => setMode(m.key)}
                                style={{ rotate: m.rotate }}
                                className="cursor-pointer rounded-[12px] border-2 border-ink bg-white px-3 py-3.5 text-center shadow-[4px_4px_0_rgba(28,25,23,.12)] transition-transform hover:-translate-y-0.5 hover:bg-brand-pale"
                            >
                                <m.icon className="mx-auto size-5 text-brand" />
                                <span className="mt-1.5 block text-[13px] font-bold">
                                    {m.title}
                                </span>
                                <span className="mt-0.5 block text-[11px] leading-snug text-stone-500">
                                    {m.blurb}
                                </span>
                            </button>
                        ))}
                        <p className="text-center text-[11.5px] text-stone-400 sm:col-span-2">
                            Tip: you can also drop files straight onto the
                            inbox, or forward emails to your dump address.
                        </p>
                    </div>
                ) : mode === 'regular' ? (
                    <RegularForm activityTypes={activityTypes} onDone={close} />
                ) : (
                    <form onSubmit={submit} className="grid gap-4">
                        {mode === 'files' && (
                            <>
                                <FileDropzone
                                    files={form.data.files}
                                    onFiles={(files) =>
                                        form.setData('files', files)
                                    }
                                />
                                <InputError message={form.errors.files} />
                                <OptionalNote
                                    form={form}
                                    label="Anything the file doesn't say? (optional)"
                                />
                            </>
                        )}

                        {mode === 'link' && (
                            <>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="new-url">
                                        Paste the link
                                    </Label>
                                    <Input
                                        id="new-url"
                                        type="url"
                                        autoFocus
                                        value={form.data.url}
                                        onChange={(e) =>
                                            form.setData('url', e.target.value)
                                        }
                                        placeholder="https://…"
                                    />
                                    <InputError message={form.errors.url} />
                                </div>
                                <OptionalNote
                                    form={form}
                                    label="Why does it matter to you? (optional)"
                                />
                            </>
                        )}

                        {mode === 'voice' && (
                            <>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="new-voice-notes">
                                        Talk it out
                                    </Label>
                                    <DictatedTextarea
                                        id="new-voice-notes"
                                        autoFocus
                                        value={form.data.notes}
                                        onValueChange={(notes) =>
                                            form.setData('notes', notes)
                                        }
                                        rows={8}
                                        placeholder="Hit the mic and ramble — what it was, when, what you took away. We type it up, then the AI files it and pulls out the nuggets."
                                    />
                                    <InputError message={form.errors.notes} />
                                </div>
                            </>
                        )}

                        {mode === 'debrief' && (
                            <>
                                <div className="grid grid-cols-[1fr_9rem] gap-3">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="new-title">
                                            What was it?
                                        </Label>
                                        <DictatedInput
                                            id="new-title"
                                            autoFocus
                                            value={form.data.title}
                                            onValueChange={(title) =>
                                                form.setData('title', title)
                                            }
                                            placeholder="e.g. Liver MRI masterclass"
                                        />
                                        <InputError
                                            message={form.errors.title}
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="new-occurred-on">
                                            When?
                                        </Label>
                                        <Input
                                            id="new-occurred-on"
                                            type="date"
                                            value={form.data.occurred_on}
                                            onChange={(e) =>
                                                form.setData(
                                                    'occurred_on',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="new-notes">
                                        Your notes (optional)
                                    </Label>
                                    <DictatedTextarea
                                        id="new-notes"
                                        value={form.data.notes}
                                        onValueChange={(notes) =>
                                            form.setData('notes', notes)
                                        }
                                        rows={9}
                                        placeholder="A sentence or your whole lecture notes — type, paste or dictate. Anything you bolded, highlighted or headed up gets treated as a key point. The AI pulls out the nuggets and the things you said you'd chase."
                                    />
                                    <InputError message={form.errors.notes} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="new-debrief-url">
                                        Link (optional)
                                    </Label>
                                    <Input
                                        id="new-debrief-url"
                                        type="url"
                                        value={form.data.url}
                                        onChange={(e) =>
                                            form.setData('url', e.target.value)
                                        }
                                        placeholder="https://… — we'll read the page too"
                                    />
                                    <InputError message={form.errors.url} />
                                </div>
                            </>
                        )}

                        <Button
                            type="submit"
                            disabled={form.processing || !canSubmit}
                            className="border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
                        >
                            {form.processing ? (
                                <Loader2 className="size-4 animate-spin" />
                            ) : (
                                <Sparkle size={14} />
                            )}
                            Dump it
                        </Button>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}

function OptionalNote({
    form,
    label,
}: {
    form: ReturnType<
        typeof useForm<{
            title: string;
            details: string;
            notes: string;
            occurred_on: string;
            url: string;
            files: File[];
        }>
    >;
    label: ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor="new-details">{label}</Label>
            <DictatedTextarea
                id="new-details"
                value={form.data.details}
                onValueChange={(details) => form.setData('details', details)}
                rows={3}
                placeholder="Ramble freely — dates, who it was for, what you took away. The AI tidies it up."
            />
        </div>
    );
}

function FileDropzone({
    files,
    onFiles,
}: {
    files: File[];
    onFiles: (files: File[]) => void;
}) {
    const [over, setOver] = useState(false);

    const add = (incoming: File[]) =>
        onFiles([...files, ...incoming].slice(0, 5));

    return (
        <div className="grid gap-2">
            <label
                onDragOver={(e) => {
                    e.preventDefault();
                    setOver(true);
                }}
                onDragLeave={() => setOver(false)}
                onDrop={(e) => {
                    e.preventDefault();
                    setOver(false);
                    add(Array.from(e.dataTransfer.files));
                }}
                className={`block cursor-pointer rounded-[12px] border-2 border-dashed px-4 py-8 text-center transition-colors ${
                    over
                        ? 'border-brand bg-brand-pale'
                        : 'border-stone-400 bg-white hover:border-ink'
                }`}
            >
                <input
                    type="file"
                    multiple
                    accept={FILE_ACCEPT}
                    className="sr-only"
                    onChange={(e) => {
                        add(Array.from(e.target.files ?? []));
                        e.target.value = '';
                    }}
                />
                <FileUp className="mx-auto size-6 text-brand" />
                <span className="mt-1.5 block text-sm font-semibold">
                    Drop files here, or click to browse
                </span>
                <span className="block text-[11.5px] text-stone-500">
                    PDFs, photos, documents — up to 5, 25&nbsp;MB each
                </span>
            </label>

            {files.length > 0 && (
                <ul className="grid gap-1">
                    {files.map((file, i) => (
                        <li
                            key={`${file.name}-${i}`}
                            className="flex items-center gap-2 rounded-[8px] border border-ink/15 bg-white px-2.5 py-1.5 text-[12.5px]"
                        >
                            <Paperclip className="size-3.5 shrink-0 text-stone-400" />
                            <span className="min-w-0 flex-1 truncate font-semibold">
                                {file.name}
                            </span>
                            <span className="shrink-0 text-stone-400">
                                {(file.size / 1024 / 1024).toFixed(1)} MB
                            </span>
                            <button
                                type="button"
                                aria-label={`Remove ${file.name}`}
                                onClick={() =>
                                    onFiles(files.filter((_, j) => j !== i))
                                }
                                className="shrink-0 cursor-pointer text-stone-400 hover:text-ink"
                            >
                                <X className="size-3.5" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function ReviewDialog({
    item,
    reference,
    retention,
    onClose,
    onMergeWith,
    onMergeInstead,
}: {
    item: InboxItemData;
    reference: ReferenceData;
    retention: 'ask' | 'always' | 'never';
    onClose: () => void;
    onMergeWith: () => void;
    onMergeInstead: () => void;
}) {
    const analysis = item.ai_analysis;

    const [step, setStep] = useState(0);

    const [values, setValues] = useState<EvidenceFormValues>({
        title: analysis?.title ?? '',
        activity_type_slug: analysis?.activity_type_slug ?? '',
        starts_on: analysis?.starts_on ?? '',
        ends_on: analysis?.ends_on ?? '',
        organisation: analysis?.organisation ?? '',
        cpd_points: analysis?.cpd_points ?? 0,
        summary: analysis?.summary ?? '',
        // Pre-rename analyses carried plain-string suggested_learning_points;
        // fall back so old Ready items aren't blanked.
        nuggets:
            analysis?.nuggets ??
            (analysis?.suggested_learning_points ?? []).map((text) => ({
                id: crypto.randomUUID(),
                text,
                done: false,
            })),
        actions: analysis?.actions ?? [],
        // The user's own words, verbatim: their pasted debrief, or what the
        // analyst copied out (email commentary, voice transcript).
        source_notes:
            (item.raw_payload.notes as string | undefined) ??
            analysis?.user_notes ??
            '',
        selected_takeaway_ids: [],
        // The analyst leaves answers null when the user's words held no
        // reflection — coerce for the controlled fields.
        reflection: Object.fromEntries(
            Object.entries(analysis?.reflection_draft ?? {}).map(
                ([key, text]) => [key, text ?? ''],
            ),
        ),
        category_slugs: analysis?.category_slugs ?? [],
        domain_codes: analysis?.domain_codes ?? [],
        attribute_codes: analysis?.attribute_codes ?? [],
        project_ids: analysis?.suggested_project_ids ?? [],
    });

    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [neverAgain, setNeverAgain] = useState(false);
    const [confirmingSave, setConfirmingSave] = useState(false);
    const [editingTitle, setEditingTitle] = useState(false);

    const keepableFiles = item.attachments.filter((a) => !a.purged);
    const gateActive = item.pii_gate;

    const piiFlags = item.ai_warnings?.pii_flags ?? [];
    const canIgnore = item.source === 'calendar' || item.source === 'email';
    const ignoreValue =
        (item.raw_payload.title as string | undefined) ??
        (item.raw_payload.subject as string | undefined) ??
        values.title;

    const submitApprove = (keepIds: number[], piiAck: boolean) => {
        setProcessing(true);

        const payload: Partial<EvidenceFormValues> = { ...values };
        delete payload.selected_takeaway_ids;

        // Takeaways are opt-in per item: only the selected ones are kept —
        // everything else is discarded, never recorded.
        const chosen = new Set(values.selected_takeaway_ids);

        router.post(
            `/inbox/${item.id}/approve`,
            {
                ...payload,
                nuggets: values.nuggets.filter((n) => chosen.has(n.id)),
                actions: values.actions.filter((a) => chosen.has(a.id)),
                cpd_points: Number(values.cpd_points),
                starts_on: values.starts_on || null,
                ends_on: values.ends_on || null,
                reflection_draft: values.reflection,
                keep_attachment_ids: keepIds,
                pii_ack: piiAck,
            },
            {
                onSuccess: onClose,
                onError: (errs) => {
                    setConfirmingSave(false);
                    setErrors(errs as Record<string, string>);
                    setStep(stepForErrors(errs as Record<string, string>));
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    const approve = () => {
        if (
            gateActive ||
            (retention === 'ask' && keepableFiles.length > 0)
        ) {
            setConfirmingSave(true);

            return;
        }

        submitApprove([], false);
    };

    /** Text-only sensitive info: scrub it server-side, then approve. */
    const removeInfoAndApprove = () => {
        setProcessing(true);
        router.post(
            `/inbox/${item.id}/remove-pii`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => submitApprove([], false),
                onError: () => setProcessing(false),
            },
        );
    };

    const dismiss = () => {
        setProcessing(true);
        router.delete(`/inbox/${item.id}`, {
            data: neverAgain
                ? {
                      ignore_rule: {
                          field: 'title',
                          operator: 'contains',
                          value: ignoreValue,
                      },
                  }
                : {},
            onSuccess: onClose,
            onFinish: () => setProcessing(false),
        });
    };

    const retry = () => {
        setProcessing(true);
        router.post(
            `/inbox/${item.id}/retry`,
            {},
            { onSuccess: onClose, onFinish: () => setProcessing(false) },
        );
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                onOpenAutoFocus={(e) => e.preventDefault()}
                className="!flex max-h-[92vh] w-[min(100vw-2rem,52rem)] flex-col overflow-hidden sm:max-w-3xl"
            >
                <DialogHeader>
                    {item.status === 'failed' ? (
                        <DialogTitle className="font-display text-2xl font-extrabold">
                            Analysis failed
                        </DialogTitle>
                    ) : (
                        <DialogTitle className="mr-6 flex items-start gap-2 font-display text-2xl font-extrabold">
                            {step > 0 ? (
                                <span className="min-w-0">
                                    {step === 3
                                        ? 'Key takeaways'
                                        : 'Your notes & reflections'}
                                </span>
                            ) : editingTitle ? (
                                <input
                                    autoFocus
                                    value={values.title}
                                    onChange={(e) =>
                                        setValues((v) => ({
                                            ...v,
                                            title: e.target.value,
                                        }))
                                    }
                                    onBlur={() => setEditingTitle(false)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            setEditingTitle(false);
                                        }
                                    }}
                                    className="w-full border-b-2 border-dashed border-brand bg-transparent font-display text-2xl font-extrabold focus:outline-none"
                                />
                            ) : (
                                <button
                                    type="button"
                                    title="Edit the title"
                                    onClick={() => setEditingTitle(true)}
                                    className="group flex min-w-0 cursor-text items-start gap-2 text-left"
                                >
                                    <span className="decoration-dashed decoration-1 underline-offset-4 group-hover:underline">
                                        {values.title || 'Untitled evidence'}
                                    </span>
                                    <PenLine className="mt-1.5 size-4 shrink-0 text-stone-300 group-hover:text-stone-500" />
                                </button>
                            )}
                            <span className="mt-1.5 ml-auto shrink-0 text-sm font-semibold whitespace-nowrap text-stone-400">
                                {step + 1} of {WIZARD_STEP_COUNT}
                            </span>
                        </DialogTitle>
                    )}
                    {errors.title && (
                        <p className="text-xs font-semibold text-red-600">
                            {errors.title}
                        </p>
                    )}
                </DialogHeader>

                {item.status === 'ready' &&
                    step === 0 &&
                    (item.merge_suggestions?.length ?? 0) > 0 && (
                        <div className="flex items-center gap-2 rounded-[10px] border-[1.5px] border-dashed border-brand/60 bg-brand-pale px-3.5 py-2 text-[13px]">
                            <Sparkle
                                size={14}
                                className="shrink-0 text-brand"
                            />
                            <span className="min-w-0 flex-1 truncate">
                                Looks like{' '}
                                <span className="font-bold">
                                    “{item.merge_suggestions![0].title}”
                                </span>
                                {item.merge_suggestions!.length > 1 &&
                                    ` +${item.merge_suggestions!.length - 1} more`}
                            </span>
                            <Button
                                size="sm"
                                onClick={onMergeInstead}
                                disabled={processing}
                                className="shrink-0 border-2 border-ink font-bold shadow-[2px_2px_0_#1c1917]"
                            >
                                <Merge className="size-3.5" /> Merge instead
                            </Button>
                        </div>
                    )}

                {item.attachments.length > 0 &&
                    (item.status === 'failed' || step === 0) && (
                        <AttachmentLinks attachments={item.attachments} />
                    )}

                {item.status === 'failed' ? (
                    <div className="grid gap-4">
                        <p className="text-sm text-stone-600">
                            {item.failure_reason}
                        </p>
                        <div className="flex gap-2">
                            <Button onClick={retry} disabled={processing}>
                                <RefreshCw className="size-4" /> Retry analysis
                            </Button>
                            <Button
                                variant="outline"
                                onClick={dismiss}
                                disabled={processing}
                            >
                                Bin it
                            </Button>
                        </div>
                    </div>
                ) : (
                    <>
                        <EvidenceWizard
                            step={step}
                            onStepChange={setStep}
                            values={values}
                            onChange={(patch) =>
                                setValues((v) => ({ ...v, ...patch }))
                            }
                            reference={reference}
                            errors={errors}
                            processing={processing}
                            primaryLabel="Approve"
                            onPrimary={approve}
                            hideTitle
                            initialComposedNotes={
                                (item.raw_payload.notes as
                                    | string
                                    | undefined) ??
                                analysis?.user_notes ??
                                ''
                            }
                            lastStepExtra={
                                canIgnore ? (
                                    <label className="flex items-start gap-2 text-[13px] text-stone-600">
                                        <Checkbox
                                            checked={neverAgain}
                                            onCheckedChange={(v) =>
                                                setNeverAgain(v === true)
                                            }
                                            className="mt-0.5"
                                        />
                                        <span>
                                            If I bin this, never show me items
                                            like it again{' '}
                                            <span className="text-stone-400">
                                                (title contains “{ignoreValue}
                                                ”)
                                            </span>
                                        </span>
                                    </label>
                                ) : undefined
                            }
                            footerExtras={
                                <>
                                    <Button
                                        variant="ghost"
                                        onClick={() => {
                                            if (
                                                window.confirm(
                                                    'Bin this item? Binned means deleted — the draft, its analysis and any files are gone for good.',
                                                )
                                            ) {
                                                dismiss();
                                            }
                                        }}
                                        disabled={processing}
                                        className="text-stone-500"
                                    >
                                        <Trash2 className="size-4 text-red-600" />{' '}
                                        Bin it
                                    </Button>
                                    {step === 0 && (
                                        <span className="absolute left-1/2 -translate-x-1/2">
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <Button
                                                        variant="outline"
                                                        onClick={onMergeWith}
                                                        disabled={processing}
                                                        className="border-2 border-stone-300 text-stone-600 hover:border-stone-400"
                                                    >
                                                        <Merge className="size-4" />{' '}
                                                        Merge with…
                                                    </Button>
                                                </TooltipTrigger>
                                                <TooltipContent className="max-w-60 text-center">
                                                    Several dumps about the
                                                    same event? Combine this
                                                    with other inbox items or
                                                    activities into one
                                                    portfolio entry.
                                                </TooltipContent>
                                            </Tooltip>
                                        </span>
                                    )}
                                </>
                            }
                            footerRight={
                                analysis ? (
                                    <span className="text-[11.5px] text-stone-400">
                                        AI confidence{' '}
                                        {(analysis.confidence * 100).toFixed(
                                            0,
                                        )}
                                        %
                                    </span>
                                ) : undefined
                            }
                        />
                        {errors.pii && (
                            <p className="text-[12.5px] font-semibold text-red-600">
                                {errors.pii}
                            </p>
                        )}
                        {confirmingSave && (
                            <ApproveConfirmDialog
                                files={
                                    retention === 'ask' ? keepableFiles : []
                                }
                                flags={gateActive ? piiFlags : []}
                                flagLocation={
                                    gateActive &&
                                    keepableFiles.length > 0 &&
                                    retention !== 'ask'
                                        ? 'an attached file'
                                        : undefined
                                }
                                verb="Approve"
                                processing={processing}
                                onConfirm={submitApprove}
                                onRemoveInfo={
                                    gateActive && keepableFiles.length === 0
                                        ? removeInfoAndApprove
                                        : undefined
                                }
                                onCancel={() => setConfirmingSave(false)}
                            />
                        )}
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

const REMINDER_LABELS: Record<RecurrenceData['reminder'], string> = {
    same_day: 'Email me the same day',
    weekly: 'Mention in my weekly email',
    none: 'No reminders',
};

function RegularForm({
    activityTypes,
    onDone,
}: {
    activityTypes: ReferenceData['activityTypes'];
    onDone: () => void;
}) {
    const form = useForm<{
        kind: 'scheduled' | 'expectation';
        title: string;
        activity_type_slug: string;
        cpd_points: number | string;
        frequency: 'weekly' | 'fortnightly' | 'monthly';
        expected_per_year: number | string;
        reminder: RecurrenceData['reminder'];
    }>({
        kind: 'scheduled',
        title: '',
        activity_type_slug: activityTypes[0]?.slug ?? 'meeting',
        cpd_points: 0.5,
        frequency: 'weekly',
        expected_per_year: 4,
        reminder: 'weekly',
    });

    const scheduled = form.data.kind === 'scheduled';

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/recurrences', { onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="grid gap-4">
            <div className="grid grid-cols-2 gap-2">
                {(
                    [
                        [
                            'scheduled',
                            'On a schedule',
                            'Weekly MDT, monthly journal club',
                        ],
                        [
                            'expectation',
                            'A few times a year',
                            'Audits, exam boards — dates unknown',
                        ],
                    ] as const
                ).map(([kind, label, blurb]) => (
                    <button
                        key={kind}
                        type="button"
                        onClick={() => form.setData('kind', kind)}
                        className={`cursor-pointer rounded-[10px] border-2 px-3 py-2.5 text-left transition-colors ${
                            form.data.kind === kind
                                ? 'rotate-[-0.4deg] border-ink bg-brand-tint'
                                : 'border-dashed border-stone-300 hover:border-ink'
                        }`}
                    >
                        <span className="block text-[13px] font-bold">
                            {label}
                        </span>
                        <span className="block text-[11px] leading-snug text-stone-500">
                            {blurb}
                        </span>
                    </button>
                ))}
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="regular-title">What is it?</Label>
                <DictatedInput
                    id="regular-title"
                    autoFocus
                    value={form.data.title}
                    onValueChange={(title) => form.setData('title', title)}
                    placeholder={
                        scheduled ? 'e.g. Lung MDT' : 'e.g. Audit meeting'
                    }
                />
                <InputError message={form.errors.title} />
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-1.5">
                    <Label>Type</Label>
                    <Select
                        value={form.data.activity_type_slug}
                        onValueChange={(v) =>
                            form.setData('activity_type_slug', v)
                        }
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {activityTypes.map((t) => (
                                <SelectItem key={t.slug} value={t.slug}>
                                    {t.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="regular-points">CPD points each time</Label>
                    <Input
                        id="regular-points"
                        type="number"
                        min={0}
                        step={0.5}
                        value={form.data.cpd_points}
                        onChange={(e) =>
                            form.setData('cpd_points', e.target.value)
                        }
                    />
                    <InputError message={form.errors.cpd_points} />
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
                {scheduled ? (
                    <div className="grid gap-1.5">
                        <Label>How often?</Label>
                        <Select
                            value={form.data.frequency}
                            onValueChange={(v) =>
                                form.setData(
                                    'frequency',
                                    v as typeof form.data.frequency,
                                )
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="weekly">Weekly</SelectItem>
                                <SelectItem value="fortnightly">
                                    Fortnightly
                                </SelectItem>
                                <SelectItem value="monthly">Monthly</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                ) : (
                    <div className="grid gap-1.5">
                        <Label htmlFor="regular-per-year">Times per year</Label>
                        <Input
                            id="regular-per-year"
                            type="number"
                            min={1}
                            max={52}
                            value={form.data.expected_per_year}
                            onChange={(e) =>
                                form.setData(
                                    'expected_per_year',
                                    e.target.value,
                                )
                            }
                        />
                        <InputError message={form.errors.expected_per_year} />
                    </div>
                )}
                <div className="grid gap-1.5">
                    <Label>Reminders</Label>
                    <Select
                        value={form.data.reminder}
                        onValueChange={(v) =>
                            form.setData(
                                'reminder',
                                v as RecurrenceData['reminder'],
                            )
                        }
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {(
                                Object.entries(REMINDER_LABELS) as [
                                    RecurrenceData['reminder'],
                                    string,
                                ][]
                            ).map(([value, label]) => (
                                <SelectItem key={value} value={value}>
                                    {label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <p className="text-[12px] leading-snug text-stone-500">
                {scheduled
                    ? 'A ready-to-approve draft appears in your inbox at each occurrence — no AI cost, your own words.'
                    : 'If a stretch passes with none captured, a prompt appears in your inbox asking whether one happened. Real emails and calendar events that match get counted automatically.'}
            </p>

            <Button
                type="submit"
                disabled={form.processing || form.data.title.trim() === ''}
                className="border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
            >
                {form.processing ? (
                    <Loader2 className="size-4 animate-spin" />
                ) : (
                    <Repeat className="size-4" />
                )}
                Save regular activity
            </Button>
        </form>
    );
}

function RegularsStrip({
    recurrences,
    onAdd,
    onManage,
}: {
    recurrences: RecurrenceData[];
    onAdd: () => void;
    onManage: (recurrence: RecurrenceData) => void;
}) {
    return (
        <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-dashed border-stone-300 pt-3">
            <span className="text-[10px] font-bold tracking-[0.08em] text-stone-400 uppercase">
                Regulars
            </span>
            {recurrences.map((recurrence) => (
                <button
                    key={recurrence.id}
                    type="button"
                    onClick={() => onManage(recurrence)}
                    title="Manage this regular activity"
                    className={`flex cursor-pointer items-center gap-1.5 rounded-full border-[1.5px] px-2.5 py-1 text-[11.5px] font-semibold transition-colors ${
                        recurrence.is_active
                            ? 'border-ink bg-white hover:bg-brand-tint'
                            : 'border-dashed border-stone-300 text-stone-400 hover:border-ink'
                    }`}
                >
                    <Repeat className="size-3 text-brand" />
                    {recurrence.title}
                    <span className="text-stone-400">
                        {recurrence.kind === 'scheduled'
                            ? recurrence.frequency
                            : `${recurrence.captured ?? 0}/${recurrence.expected_per_year}`}
                    </span>
                    {!recurrence.is_active && <span>· paused</span>}
                </button>
            ))}
            <button
                type="button"
                onClick={onAdd}
                className="flex cursor-pointer items-center gap-1 rounded-full border-[1.5px] border-dashed border-stone-400 px-2.5 py-1 text-[11.5px] font-semibold text-stone-500 transition-colors hover:border-ink hover:text-ink"
            >
                <Plus className="size-3" /> add a regular
            </button>
        </div>
    );
}

function ManageRecurrenceDialog({
    recurrence,
    onClose,
}: {
    recurrence: RecurrenceData;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);

    const patch = (data: { is_active?: boolean; reminder?: string }) => {
        setProcessing(true);
        router.patch(`/recurrences/${recurrence.id}`, data, {
            preserveScroll: true,
            onSuccess: onClose,
            onFinish: () => setProcessing(false),
        });
    };

    const remove = () => {
        if (
            !window.confirm(
                `Remove "${recurrence.title}"? Unresolved drafts are binned; approved activities stay.`,
            )
        ) {
            return;
        }

        setProcessing(true);
        router.delete(`/recurrences/${recurrence.id}`, {
            preserveScroll: true,
            onSuccess: onClose,
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 font-display text-2xl font-extrabold">
                        <Repeat className="size-5 text-brand" />
                        {recurrence.title}
                    </DialogTitle>
                </DialogHeader>

                <p className="text-[13px] text-stone-500">
                    {recurrence.kind === 'scheduled'
                        ? `Repeats ${recurrence.frequency}${recurrence.type ? ` · ${recurrence.type}` : ''}. A draft lands in your inbox at each occurrence.`
                        : `Expected ${recurrence.expected_per_year}× a year${recurrence.type ? ` · ${recurrence.type}` : ''} — ${recurrence.captured ?? 0} captured so far this appraisal year.`}
                </p>

                <div className="grid gap-1.5">
                    <Label>Reminders</Label>
                    <Select
                        value={recurrence.reminder}
                        onValueChange={(v) => patch({ reminder: v })}
                        disabled={processing}
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {(
                                Object.entries(REMINDER_LABELS) as [
                                    RecurrenceData['reminder'],
                                    string,
                                ][]
                            ).map(([value, label]) => (
                                <SelectItem key={value} value={value}>
                                    {label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <div className="flex flex-wrap items-center gap-2 border-t border-dashed border-stone-300 pt-4">
                    <Button
                        disabled={processing}
                        onClick={() => {
                            setProcessing(true);
                            router.post(
                                `/recurrences/${recurrence.id}/occurrence`,
                                {},
                                {
                                    preserveScroll: true,
                                    onSuccess: onClose,
                                    onFinish: () => setProcessing(false),
                                },
                            );
                        }}
                        className="border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
                    >
                        <Plus className="size-4" /> Add one for today
                    </Button>
                    <Button
                        variant="outline"
                        disabled={processing}
                        onClick={() =>
                            patch({ is_active: !recurrence.is_active })
                        }
                        className="border-2 border-ink"
                    >
                        {recurrence.is_active ? 'Pause' : 'Resume'}
                    </Button>
                    <Button
                        variant="ghost"
                        disabled={processing}
                        onClick={remove}
                        className="text-red-600 hover:text-red-700"
                    >
                        <Trash2 className="size-4" /> Remove
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
