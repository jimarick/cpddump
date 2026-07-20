import { router } from '@inertiajs/react';
import { AlertTriangle, Loader2, Undo2, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { CaveatNote } from '@/components/brand/caveat-note';
import { Sparkle } from '@/components/brand/sparkle';
import { EvidenceFormFields } from '@/components/cpd/evidence-form-fields';
import type { EvidenceFormValues } from '@/components/cpd/evidence-form-fields';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { postJson } from '@/lib/api';
import type {
    MergePreview,
    MergeSeed,
    MergeSourceSummary,
    ReferenceData,
} from '@/types/cpd';

const ROW_TILTS = [-1.4, 1, -0.6, 1.3, -1];

type Reflection = Record<string, string>;

/**
 * Everything the modal's form knows, updated atomically so the preview
 * and the AI reflection can land in either order without racing.
 */
interface FormState {
    values: EvidenceFormValues | null;
    aiState: 'pending' | 'applied' | 'undone' | 'failed';
    aiReflection: Reflection | null;
    preAiReflection: Reflection | null;
}

/**
 * The merge confirmation modal: combined points, date span, files, PII
 * decisions and the full editable entry — one reflection, on the whole.
 * Deterministic defaults arrive from the preview endpoint; AI-combined
 * reflections shimmer in afterwards with a one-tap undo.
 */
export function MergeDialog({
    seed: initialSeed,
    reference,
    onClose,
}: {
    seed: MergeSeed;
    reference: ReferenceData;
    onClose: () => void;
}) {
    const [seed, setSeed] = useState<MergeSeed>(initialSeed);
    const [preview, setPreview] = useState<MergePreview | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);

    const [form, setForm] = useState<FormState>({
        values: null,
        aiState: 'pending',
        aiReflection: null,
        preAiReflection: null,
    });

    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const [keepIds, setKeepIds] = useState<number[]>([]);
    const [piiAcks, setPiiAcks] = useState<number[]>([]);

    const patchValues = (patch: Partial<EvidenceFormValues>) =>
        setForm((f) =>
            f.values ? { ...f, values: { ...f.values, ...patch } } : f,
        );

    // Deterministic seed: instant. Values initialise once from the first
    // preview; source removals refetch the preview but keep user edits.
    useEffect(() => {
        let cancelled = false;

        postJson<MergePreview>('/merges/preview', seed)
            .then((data) => {
                if (cancelled) {
                    return;
                }

                setPreview(data);
                setForm((f) => {
                    if (f.values) {
                        return f;
                    }

                    const values = valuesFromPreview(data);

                    // The AI combine already landed — apply it on arrival.
                    // AI answers layer OVER the stitched defaults: a key the
                    // AI left empty keeps its concatenated sources.
                    if (f.aiState === 'pending' && f.aiReflection) {
                        return {
                            ...f,
                            aiState: 'applied',
                            preAiReflection: values.reflection,
                            values: {
                                ...values,
                                reflection: {
                                    ...values.reflection,
                                    ...f.aiReflection,
                                },
                            },
                        };
                    }

                    return { ...f, values };
                });
            })
            .catch((e: Error) => {
                if (!cancelled) {
                    setPreviewError(e.message);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [seed]);

    // AI-combined reflections, fired once for the initial selection.
    useEffect(() => {
        let cancelled = false;

        postJson<{ reflection: Reflection }>('/merges/reflection', initialSeed)
            .then(({ reflection }) => {
                if (cancelled) {
                    return;
                }

                setForm((f) => {
                    if (Object.keys(reflection).length === 0) {
                        return f.aiState === 'pending'
                            ? { ...f, aiState: 'failed' }
                            : f;
                    }

                    if (f.values && f.aiState === 'pending') {
                        return {
                            ...f,
                            aiState: 'applied',
                            aiReflection: reflection,
                            preAiReflection: f.values.reflection,
                            values: {
                                ...f.values,
                                reflection: {
                                    ...f.values.reflection,
                                    ...reflection,
                                },
                            },
                        };
                    }

                    return { ...f, aiReflection: reflection };
                });
            })
            .catch(() => {
                if (!cancelled) {
                    setForm((f) =>
                        f.aiState === 'pending'
                            ? { ...f, aiState: 'failed' }
                            : f,
                    );
                }
            });

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const removeSource = (source: MergeSourceSummary) => {
        const next: MergeSeed = {
            activity_ids:
                source.kind === 'activity'
                    ? seed.activity_ids.filter((id) => id !== source.id)
                    : seed.activity_ids,
            inbox_item_ids:
                source.kind === 'inbox_item'
                    ? seed.inbox_item_ids.filter((id) => id !== source.id)
                    : seed.inbox_item_ids,
            into_activity_id: seed.into_activity_id,
        };

        const remaining =
            next.activity_ids.length +
            next.inbox_item_ids.length +
            (next.into_activity_id ? 1 : 0);

        if (remaining < 2) {
            onClose();

            return;
        }

        setSeed(next);
    };

    const undoAi = () =>
        setForm((f) => ({
            ...f,
            aiState: 'undone',
            values:
                f.values && f.preAiReflection
                    ? { ...f.values, reflection: f.preAiReflection }
                    : f.values,
        }));

    const redoAi = () =>
        setForm((f) =>
            f.aiReflection
                ? {
                      ...f,
                      aiState: 'applied',
                      values: f.values
                          ? {
                                ...f.values,
                                reflection: {
                                    ...(f.preAiReflection ?? {}),
                                    ...f.aiReflection,
                                },
                            }
                          : f.values,
                  }
                : f,
        );

    const gatedSources =
        preview?.sources.filter(
            (s) => s.pii_gate && !piiAcks.includes(s.id),
        ) ?? [];

    const removePii = (itemId: number) => {
        setProcessing(true);
        router.post(
            `/inbox/${itemId}/remove-pii`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => setSeed({ ...seed }),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const merge = () => {
        if (!form.values) {
            return;
        }

        setProcessing(true);
        router.post(
            '/merges',
            {
                ...seed,
                ...form.values,
                cpd_points: Number(form.values.cpd_points),
                starts_on: form.values.starts_on || null,
                ends_on: form.values.ends_on || null,
                details: form.values.summary,
                keep_attachment_ids: keepIds,
                pii_acks: piiAcks,
            },
            {
                onSuccess: onClose,
                onError: (errs) => setErrors(errs as Record<string, string>),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const sourceCount = preview?.sources.length ?? 0;
    const keepableFiles =
        preview?.retention === 'ask'
            ? (preview?.sources.flatMap((s) =>
                  s.attachments
                      .filter((a) => a.keepable)
                      .map((a) => ({ ...a, from: s.title })),
              ) ?? [])
            : [];

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[92vh] w-[min(100vw-2rem,52rem)] overflow-x-hidden overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 font-display text-2xl font-extrabold">
                        Merge {sourceCount > 0 ? sourceCount : ''} into one
                        <Sparkle size={16} className="text-brand" />
                    </DialogTitle>
                </DialogHeader>

                <p className="-mt-2 text-[13px] text-stone-500">
                    One entry replaces these — they're kept underneath and can
                    be split apart again any time.
                </p>

                {previewError && (
                    <div className="rounded-[10px] border-2 border-red-600 bg-red-50 px-4 py-3 text-sm text-red-700">
                        {previewError}
                    </div>
                )}

                {!preview && !previewError && (
                    <div className="flex items-center gap-2 py-10 text-sm text-stone-500">
                        <Loader2 className="size-4 animate-spin" /> Gathering
                        the pieces…
                    </div>
                )}

                {preview && form.values && (
                    <>
                        <div className="flex flex-wrap gap-2.5">
                            {preview.sources.map((source, i) => (
                                <div
                                    key={`${source.kind}-${source.id}`}
                                    style={{
                                        rotate: `${ROW_TILTS[i % ROW_TILTS.length]}deg`,
                                    }}
                                    className="group relative max-w-[180px] rounded-[10px] border-[1.5px] border-ink bg-white px-3 py-2 shadow-[2px_2px_0_rgba(28,25,23,.12)]"
                                >
                                    <div className="truncate text-[12px] font-bold">
                                        {source.title}
                                    </div>
                                    <div className="mt-0.5 text-[10.5px] font-semibold text-stone-500 uppercase">
                                        {source.source ??
                                            (source.is_target
                                                ? 'merged entry'
                                                : 'timeline')}
                                        {source.starts_on
                                            ? ` · ${source.starts_on}`
                                            : ''}{' '}
                                        · {source.cpd_points} pts
                                    </div>
                                    {!source.is_target && sourceCount > 2 && (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                removeSource(source)
                                            }
                                            title="Leave this one out"
                                            className="absolute -top-2 -left-2 hidden size-5 cursor-pointer items-center justify-center rounded-full border-[1.5px] border-ink bg-white text-[10px] font-bold shadow-[1.5px_1.5px_0_#1c1917] group-hover:flex"
                                        >
                                            <X className="size-3" />
                                        </button>
                                    )}
                                </div>
                            ))}
                        </div>

                        {preview.defaults.points_breakdown.length > 1 && (
                            <CaveatNote rotate={-1.5} className="-mt-1">
                                {preview.defaults.points_breakdown.join(' + ')}{' '}
                                points — trim if it double-counts
                            </CaveatNote>
                        )}

                        {gatedSources.length > 0 && (
                            <div className="rounded-[10px] border-2 border-brand bg-brand-pale px-4 py-3 text-sm">
                                <div className="flex items-center gap-2 font-bold">
                                    <AlertTriangle className="size-4 text-brand" />{' '}
                                    Possible identifiable information
                                </div>
                                <div className="mt-2 grid gap-2.5">
                                    {gatedSources.map((source) => (
                                        <div key={source.id}>
                                            <p className="text-[13px] font-semibold">
                                                “{source.title}”
                                                <span className="ml-1.5 font-normal text-stone-500">
                                                    {(source.pii_flags ?? [])
                                                        .map((f) =>
                                                            f.type.replace(
                                                                /_/g,
                                                                ' ',
                                                            ),
                                                        )
                                                        .join(', ')}
                                                </span>
                                            </p>
                                            <div className="mt-1 flex flex-wrap gap-2">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        removePii(source.id)
                                                    }
                                                    disabled={processing}
                                                    className="border-2 border-ink bg-white font-bold"
                                                >
                                                    Remove patient info
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setPiiAcks((ids) => [
                                                            ...ids,
                                                            source.id,
                                                        ])
                                                    }
                                                    disabled={processing}
                                                    className="text-stone-600"
                                                >
                                                    Keep — I've checked it
                                                </Button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                                {errors.pii && (
                                    <p className="mt-1.5 text-[12.5px] font-semibold text-red-600">
                                        {errors.pii}
                                    </p>
                                )}
                            </div>
                        )}

                        {keepableFiles.length > 0 && (
                            <div className="rounded-lg border-[1.5px] border-dashed border-stone-300 bg-stone-50 p-3">
                                <p className="text-[13px] font-semibold text-ink">
                                    Keep the file
                                    {keepableFiles.length > 1 ? 's' : ''} with
                                    the merged entry?
                                </p>
                                <p className="mt-0.5 text-[12px] text-stone-500">
                                    Unticked files are deleted when you merge —
                                    the written entry is kept either way.
                                </p>
                                <div className="mt-2 grid gap-1.5">
                                    {keepableFiles.map((file) => (
                                        <label
                                            key={file.id}
                                            className="flex min-w-0 items-start gap-2 text-[13px] text-stone-700"
                                        >
                                            <Checkbox
                                                checked={keepIds.includes(
                                                    file.id,
                                                )}
                                                onCheckedChange={(v) =>
                                                    setKeepIds((ids) =>
                                                        v === true
                                                            ? [...ids, file.id]
                                                            : ids.filter(
                                                                  (id) =>
                                                                      id !==
                                                                      file.id,
                                                              ),
                                                    )
                                                }
                                                className="mt-0.5"
                                            />
                                            {/* min-w-0 so long filenames truncate
                                                instead of inflating the dialog. */}
                                            <span className="min-w-0 flex-1 truncate">
                                                {file.name}
                                                <span className="ml-1.5 text-stone-400">
                                                    from “{file.from}”
                                                </span>
                                            </span>
                                        </label>
                                    ))}
                                </div>
                            </div>
                        )}

                        {form.aiState === 'pending' && (
                            <div className="flex items-center gap-2 rounded-[10px] border-[1.5px] border-dashed border-brand/60 bg-brand-pale px-3.5 py-2 text-[13px] text-stone-600">
                                <Sparkle size={13} className="text-brand" />
                                <Loader2 className="size-3.5 animate-spin text-brand" />
                                AI is weaving the reflections together…
                            </div>
                        )}
                        {form.aiState === 'applied' && (
                            <div className="flex items-center gap-2 rounded-[10px] border-[1.5px] border-dashed border-brand/60 bg-brand-pale px-3.5 py-2 text-[13px] text-stone-600">
                                <Sparkle size={13} className="text-brand" />
                                Reflections combined by AI — edit below, or
                                <button
                                    type="button"
                                    onClick={undoAi}
                                    className="flex cursor-pointer items-center gap-1 font-semibold underline decoration-dashed underline-offset-2 hover:text-ink"
                                >
                                    <Undo2 className="size-3" /> undo
                                </button>
                            </div>
                        )}
                        {form.aiState === 'undone' && (
                            <div className="flex items-center gap-2 rounded-[10px] border-[1.5px] border-dashed border-stone-300 px-3.5 py-2 text-[13px] text-stone-500">
                                Back to the stitched-together originals —
                                <button
                                    type="button"
                                    onClick={redoAi}
                                    className="cursor-pointer font-semibold underline decoration-dashed underline-offset-2 hover:text-ink"
                                >
                                    re-apply the AI combine
                                </button>
                            </div>
                        )}

                        <EvidenceFormFields
                            values={form.values}
                            onChange={patchValues}
                            reference={reference}
                            errors={errors}
                        />

                        <div className="mt-2 flex items-center gap-2 border-t border-dashed border-stone-300 pt-4">
                            <Button
                                onClick={merge}
                                disabled={
                                    processing || gatedSources.length > 0
                                }
                                title={
                                    gatedSources.length > 0
                                        ? 'Resolve the patient-information warnings first'
                                        : undefined
                                }
                                className="border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
                            >
                                {processing && (
                                    <Loader2 className="size-4 animate-spin" />
                                )}{' '}
                                Merge into one entry
                            </Button>
                            <Button
                                variant="outline"
                                onClick={onClose}
                                disabled={processing}
                                className="border-2 border-ink"
                            >
                                Cancel
                            </Button>
                        </div>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

function valuesFromPreview(preview: MergePreview): EvidenceFormValues {
    const d = preview.defaults;

    return {
        title: d.title,
        activity_type_slug: d.activity_type_slug ?? '',
        starts_on: d.starts_on ?? '',
        ends_on: d.ends_on ?? '',
        organisation: d.organisation ?? '',
        cpd_points: d.cpd_points,
        summary: d.details,
        reflection: d.reflection,
        category_slugs: d.category_slugs,
        domain_codes: d.domain_codes,
        attribute_codes: d.attribute_codes,
        project_ids: d.project_ids,
    };
}
