import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ChevronLeft,
    ChevronRight,
    FileUp,
    Link2,
    Loader2,
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
import { AttachmentLinks } from '@/components/cpd/attachment-links';
import {
    DictatedInput,
    DictatedTextarea,
} from '@/components/cpd/dictated-fields';
import {
    CategorisationStepFields,
    DetailsStepFields,
    ReflectionStepFields,
} from '@/components/cpd/evidence-form-fields';
import type { EvidenceFormValues } from '@/components/cpd/evidence-form-fields';
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
import type {
    InboxItemData,
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
}

export default function Inbox({
    items,
    period,
    reference,
    dumpAddress,
    recurrences,
}: Props) {
    const [reviewing, setReviewing] = useState<InboxItemData | null>(null);
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

    useEffect(() => {
        if (!analysing) {
            return;
        }

        const timer = setInterval(
            () => router.reload({ only: ['items'] }),
            5000,
        );

        return () => clearInterval(timer);
    }, [analysing]);

    return (
        <>
            <Head title="Inbox" />

            <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="font-display text-[32px] leading-none font-semibold tracking-[-0.01em]">
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
                    <div className="pointer-events-none absolute inset-x-0 top-10 bottom-14 flex items-center justify-center">
                        <div className="flex size-52 rotate-[-12deg] items-center justify-center rounded-full border-[5px] border-double border-stone-200 text-center">
                            <span className="font-display text-[26px] leading-tight font-bold tracking-[0.18em] text-stone-200">
                                CPD
                                <br />
                                DUMP
                            </span>
                        </div>
                    </div>
                    <div className="relative grid min-h-0 min-w-0 flex-1 auto-rows-min grid-cols-[minmax(0,1fr)] gap-2.5 overflow-y-auto px-1 pt-1 pb-2">
                        {items.map((item, i) => (
                            <InboxRow
                                key={item.id}
                                item={item}
                                index={i}
                                onOpen={() =>
                                    item.status === 'ready' ||
                                    item.status === 'failed'
                                        ? setReviewing(item)
                                        : undefined
                                }
                                onDelete={() =>
                                    router.delete(`/inbox/${item.id}`, {
                                        preserveScroll: true,
                                    })
                                }
                            />
                        ))}
                    </div>
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
                        <p className="mt-2 font-display text-2xl font-semibold">
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
                    onClose={() => setReviewing(null)}
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
            <h2 className="mt-4 font-display text-2xl font-semibold">
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

function InboxRow({
    item,
    index,
    onOpen,
    onDelete,
}: {
    item: InboxItemData;
    index: number;
    onOpen?: () => void;
    onDelete: () => void;
}) {
    const title =
        item.ai_analysis?.title ??
        (item.raw_payload.title as string | undefined) ??
        (item.raw_payload.subject as string | undefined) ??
        (item.raw_payload.url as string | undefined) ??
        'Untitled evidence';

    const busy = item.status === 'pending' || item.status === 'analysing';
    const failed = item.status === 'failed';
    const warnings = (item.ai_warnings?.pii_flags?.length ?? 0) > 0;

    return (
        <div
            onClick={busy ? undefined : onOpen}
            style={{ rotate: ROW_TILTS[index % ROW_TILTS.length] }}
            className={`flex w-full min-w-0 items-center gap-3 rounded-[12px] border-2 border-ink bg-white px-4 py-3 text-left shadow-[3px_3px_0_rgba(28,25,23,.12)] transition-transform md:px-5 ${
                busy
                    ? 'cursor-default opacity-70'
                    : 'cursor-pointer hover:-translate-y-0.5 hover:bg-[#fffbf8]'
            }`}
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

type DumpMode = 'files' | 'link' | 'text' | 'regular';

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
        key: 'text',
        icon: PenLine,
        title: 'Just words',
        blurb: 'Type what happened',
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
        url: string;
        files: File[];
    }>({
        title: '',
        details: '',
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
              : form.data.title.trim() !== '';

    return (
        <Dialog open onOpenChange={(o) => !o && close()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2.5 font-display text-2xl font-semibold">
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
                        {DUMP_MODES.map((m) => (
                            <button
                                key={m.key}
                                type="button"
                                onClick={() => setMode(m.key)}
                                style={{ rotate: m.rotate }}
                                className="cursor-pointer rounded-[12px] border-2 border-ink bg-white px-3 py-5 text-center shadow-[4px_4px_0_rgba(28,25,23,.12)] transition-transform hover:-translate-y-0.5 hover:bg-brand-pale"
                            >
                                <m.icon className="mx-auto size-6 text-brand" />
                                <span className="mt-2 block text-[14px] font-bold">
                                    {m.title}
                                </span>
                                <span className="mt-0.5 block text-[11.5px] leading-snug text-stone-500">
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

                        {mode === 'text' && (
                            <>
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
                                        placeholder="e.g. Taught FRCR physics revision session"
                                    />
                                    <InputError message={form.errors.title} />
                                </div>
                                <OptionalNote
                                    form={form}
                                    label="Anything else? (optional)"
                                />
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

const REVIEW_STEPS = ['Details', 'Reflection', 'Categorise'] as const;

/** Which review step each server-side validation error belongs to. */
function stepForErrors(errors: Record<string, string>): number {
    const keys = Object.keys(errors);
    const detailFields = [
        'title',
        'activity_type_slug',
        'starts_on',
        'ends_on',
        'organisation',
        'cpd_points',
        'summary',
    ];

    if (keys.some((k) => detailFields.includes(k))) {
        return 0;
    }

    if (keys.some((k) => k.startsWith('reflection'))) {
        return 1;
    }

    return 2;
}

function ReviewDialog({
    item,
    reference,
    onClose,
}: {
    item: InboxItemData;
    reference: ReferenceData;
    onClose: () => void;
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
        reflection: analysis?.reflection_draft ?? {},
        category_slugs: analysis?.category_slugs ?? [],
        domain_codes: analysis?.domain_codes ?? [],
        attribute_codes: analysis?.attribute_codes ?? [],
        project_ids: analysis?.suggested_project_ids ?? [],
    });

    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [neverAgain, setNeverAgain] = useState(false);

    const piiFlags = item.ai_warnings?.pii_flags ?? [];
    const missingEvidence = item.ai_warnings?.missing_evidence ?? [];
    const canIgnore = item.source === 'calendar' || item.source === 'email';
    const ignoreValue =
        (item.raw_payload.title as string | undefined) ??
        (item.raw_payload.subject as string | undefined) ??
        values.title;

    const approve = () => {
        setProcessing(true);
        router.post(
            `/inbox/${item.id}/approve`,
            {
                ...values,
                cpd_points: Number(values.cpd_points),
                starts_on: values.starts_on || null,
                ends_on: values.ends_on || null,
                reflection_draft: values.reflection,
            },
            {
                onSuccess: onClose,
                onError: (errs) => {
                    setErrors(errs as Record<string, string>);
                    setStep(stepForErrors(errs as Record<string, string>));
                },
                onFinish: () => setProcessing(false),
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

    const lastStep = step === REVIEW_STEPS.length - 1;

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[92vh] w-[min(100vw-2rem,52rem)] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 font-display text-2xl font-semibold">
                        {item.status === 'failed'
                            ? 'Analysis failed'
                            : 'Review the draft'}
                        {item.status === 'ready' && (
                            <Sparkle size={16} className="text-brand" />
                        )}
                    </DialogTitle>
                </DialogHeader>

                {item.status !== 'failed' && (
                    <div className="flex items-center gap-1.5">
                        {REVIEW_STEPS.map((label, i) => (
                            <button
                                key={label}
                                type="button"
                                onClick={() => setStep(i)}
                                className={`flex cursor-pointer items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition-colors ${
                                    i === step
                                        ? 'rotate-[-0.5deg] border-[1.5px] border-ink bg-brand-tint text-brand-dark'
                                        : 'border-[1.5px] border-dashed border-stone-300 text-stone-500 hover:border-ink hover:text-ink'
                                }`}
                            >
                                <span
                                    className={`flex size-4 items-center justify-center rounded-full text-[10px] font-bold ${
                                        i === step
                                            ? 'bg-brand text-white'
                                            : 'bg-stone-200 text-stone-600'
                                    }`}
                                >
                                    {i + 1}
                                </span>
                                {label}
                            </button>
                        ))}
                    </div>
                )}

                {piiFlags.length > 0 && (
                    <div className="rounded-[10px] border-2 border-brand bg-brand-pale px-4 py-3 text-sm">
                        <div className="flex items-center gap-2 font-bold">
                            <AlertTriangle className="size-4 text-brand" />{' '}
                            Possible identifiable information
                        </div>
                        <ul className="mt-1 list-disc pl-5 text-[13px] text-stone-600">
                            {piiFlags.map((flag, i) => (
                                <li key={i}>
                                    <span className="font-semibold">
                                        {flag.type.replace('_', ' ')}
                                    </span>
                                    : “{flag.excerpt}”
                                </li>
                            ))}
                        </ul>
                        <p className="mt-1 text-[12.5px] text-stone-500">
                            Edit these out before approving — patients and
                            colleagues must never be identifiable.
                        </p>
                    </div>
                )}

                {item.attachments.length > 0 && (
                    <AttachmentLinks attachments={item.attachments} />
                )}

                {missingEvidence.length > 0 && step === 0 && (
                    <div className="rounded-[10px] border border-dashed border-stone-400 px-4 py-2.5 text-[13px] text-stone-600">
                        <span className="font-semibold">Might be missing:</span>{' '}
                        {missingEvidence.join(' · ')}
                    </div>
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
                        {step === 0 && (
                            <DetailsStepFields
                                values={values}
                                onChange={(patch) =>
                                    setValues((v) => ({ ...v, ...patch }))
                                }
                                reference={reference}
                                errors={errors}
                            />
                        )}
                        {step === 1 && (
                            <ReflectionStepFields
                                values={values}
                                onChange={(patch) =>
                                    setValues((v) => ({ ...v, ...patch }))
                                }
                                reference={reference}
                                errors={errors}
                            />
                        )}
                        {step === 2 && (
                            <CategorisationStepFields
                                values={values}
                                onChange={(patch) =>
                                    setValues((v) => ({ ...v, ...patch }))
                                }
                                reference={reference}
                                errors={errors}
                            />
                        )}

                        <div className="mt-2 grid gap-3 border-t border-dashed border-stone-300 pt-4">
                            {canIgnore && lastStep && (
                                <label className="flex items-start gap-2 text-[13px] text-stone-600">
                                    <Checkbox
                                        checked={neverAgain}
                                        onCheckedChange={(v) =>
                                            setNeverAgain(v === true)
                                        }
                                        className="mt-0.5"
                                    />
                                    <span>
                                        If I bin this, never show me items like
                                        it again{' '}
                                        <span className="text-stone-400">
                                            (title contains “{ignoreValue}”)
                                        </span>
                                    </span>
                                </label>
                            )}
                            <div className="flex flex-wrap items-center gap-2">
                                {step > 0 && (
                                    <Button
                                        variant="outline"
                                        onClick={() => setStep(step - 1)}
                                        disabled={processing}
                                        className="border-2 border-ink"
                                    >
                                        <ChevronLeft className="size-4" /> Back
                                    </Button>
                                )}
                                {!lastStep ? (
                                    <Button
                                        onClick={() => setStep(step + 1)}
                                        disabled={processing}
                                        className="border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
                                    >
                                        Next <ChevronRight className="size-4" />
                                    </Button>
                                ) : (
                                    <Button
                                        onClick={approve}
                                        disabled={processing}
                                        className="border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
                                    >
                                        {processing && (
                                            <Loader2 className="size-4 animate-spin" />
                                        )}{' '}
                                        Approve
                                    </Button>
                                )}
                                <Button
                                    variant="ghost"
                                    onClick={dismiss}
                                    disabled={processing}
                                    className="text-stone-500"
                                >
                                    Bin it
                                </Button>
                                <span className="ml-auto text-[11.5px] text-stone-400">
                                    {analysis &&
                                        `AI confidence ${(analysis.confidence * 100).toFixed(0)}%`}
                                </span>
                            </div>
                        </div>
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
                    <DialogTitle className="flex items-center gap-2 font-display text-2xl font-semibold">
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
