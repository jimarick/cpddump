import { router } from '@inertiajs/react';
import { Loader2, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { CaveatNote } from '@/components/brand/caveat-note';
import { Sparkle } from '@/components/brand/sparkle';
import { ApproveConfirmDialog } from '@/components/cpd/approve-confirm-dialog';
import type { EvidenceFormValues } from '@/components/cpd/evidence-form-fields';
import {
    EvidenceWizard,
    stepForErrors,
    WIZARD_STEP_COUNT,
} from '@/components/cpd/evidence-wizard';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { postJson } from '@/lib/api';
import type {
    MergeDraft,
    MergePreview,
    MergeSeed,
    MergeSourceSummary,
    ReferenceData,
} from '@/types/cpd';

const ROW_TILTS = [-1.4, 1, -0.6, 1.3, -1];

/**
 * Everything the modal's form knows, updated atomically so the preview
 * and the AI draft can land in either order without racing.
 */
interface FormState {
    values: EvidenceFormValues | null;
    aiDraft: MergeDraft | null;
    aiApplied: boolean;
    /**
     * True while /merges/draft is in flight. The wizard is held back
     * behind a "drafting…" banner until this settles, so the boxes never
     * show stitched defaults that silently swap to AI text seconds later.
     */
    aiPending: boolean;
}

/**
 * AI values layer OVER the deterministic defaults: anything the draft
 * left null or empty keeps its stitched-together starting value.
 */
function applyDraft(
    values: EvidenceFormValues,
    draft: MergeDraft,
): EvidenceFormValues {
    return {
        ...values,
        title: draft.title ?? values.title,
        activity_type_slug:
            draft.activity_type_slug ?? values.activity_type_slug,
        organisation: draft.organisation ?? values.organisation,
        summary: draft.details ?? values.summary,
        reflection: { ...values.reflection, ...draft.reflection },
    };
}

/**
 * The merge confirmation modal: the same Details → Reflection → Categorise
 * wizard as the inbox review, seeded with deterministic defaults from the
 * preview endpoint and silently overlaid with the AI-combined draft. PII
 * and keep-file decisions happen in the ApproveConfirmDialog popup when
 * Merge is clicked.
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
        aiDraft: null,
        aiApplied: false,
        aiPending: true,
    });

    const [step, setStep] = useState(0);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [confirmingSave, setConfirmingSave] = useState(false);

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

                    // The AI draft already landed — apply it on arrival.
                    if (f.aiDraft && !f.aiApplied) {
                        return {
                            ...f,
                            aiApplied: true,
                            values: applyDraft(values, f.aiDraft),
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

    // The AI-drafted combined entry, fired once for the initial selection.
    // The form waits behind the drafting banner until this settles —
    // success, empty or failure — then shows the final text straight away.
    useEffect(() => {
        let cancelled = false;

        postJson<{ draft: MergeDraft }>('/merges/draft', initialSeed)
            .then(({ draft }) => {
                if (cancelled) {
                    return;
                }

                const empty =
                    !draft.title &&
                    !draft.details &&
                    Object.keys(draft.reflection).length === 0;

                setForm((f) => {
                    if (empty) {
                        return { ...f, aiPending: false };
                    }

                    if (f.values && !f.aiApplied) {
                        return {
                            ...f,
                            aiDraft: draft,
                            aiApplied: true,
                            aiPending: false,
                            values: applyDraft(f.values, draft),
                        };
                    }

                    return { ...f, aiDraft: draft, aiPending: false };
                });
            })
            .catch(() => {
                // Draft failure is silent: the stitched defaults stand.
                if (!cancelled) {
                    setForm((f) => ({ ...f, aiPending: false }));
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

    const gatedSources =
        preview?.sources.filter((s) => s.pii_gate) ?? [];

    const submitMerge = (keepIds: number[], piiAcks: number[]) => {
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
                onError: (errs) => {
                    setConfirmingSave(false);
                    setErrors(errs as Record<string, string>);
                    setStep(stepForErrors(errs as Record<string, string>));
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    const merge = () => {
        if (gatedSources.length > 0 || keepableFiles.length > 0) {
            setConfirmingSave(true);

            return;
        }

        submitMerge([], []);
    };

    /** Text-only sensitive info: scrub every gated source, then merge. */
    const removeInfoAndMerge = () => {
        setProcessing(true);

        const ids = gatedSources.map((s) => s.id);
        const next = (i: number) => {
            if (i >= ids.length) {
                submitMerge([], []);

                return;
            }

            router.post(
                `/inbox/${ids[i]}/remove-pii`,
                {},
                {
                    preserveScroll: true,
                    onSuccess: () => next(i + 1),
                    onError: () => setProcessing(false),
                },
            );
        };

        next(0);
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
            <DialogContent
                onOpenAutoFocus={(e) => e.preventDefault()}
                className="!flex max-h-[92vh] w-[min(100vw-2rem,52rem)] flex-col overflow-hidden *:min-w-0 sm:max-w-3xl"
            >
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 font-display text-2xl font-extrabold">
                        Merge {sourceCount > 0 ? sourceCount : ''} into one
                        <Sparkle size={16} className="text-brand" />
                        <span className="text-sm font-semibold text-stone-400">
                            {step + 1} of {WIZARD_STEP_COUNT}
                        </span>
                    </DialogTitle>
                </DialogHeader>

                <p className="-mt-2 text-[13px] text-stone-500">
                    {form.aiApplied
                        ? `Title, details and reflections drafted by AI from all ${sourceCount} — one entry replaces them; they're kept underneath and can be split apart again any time.`
                        : "One entry replaces these — they're kept underneath and can be split apart again any time."}
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

                {preview && (
                    <>
                        {step === 0 && (
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
                                            {!source.is_target &&
                                                sourceCount > 2 && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            removeSource(
                                                                source,
                                                            )
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

                                {preview.defaults.points_breakdown.length >
                                    1 && (
                                    <CaveatNote rotate={-1.5} className="-mt-1">
                                        {preview.defaults.points_breakdown.join(
                                            ' + ',
                                        )}{' '}
                                        points — trim if it double-counts
                                    </CaveatNote>
                                )}
                            </>
                        )}
                    </>
                )}

                {preview && !previewError && form.aiPending && (
                    <div className="flex items-center justify-center gap-2.5 rounded-[12px] border-2 border-dashed border-brand bg-brand-pale px-5 py-10 text-sm font-semibold text-brand-dark">
                        <Sparkle size={14} className="shrink-0 text-brand" />
                        <Loader2 className="size-4 shrink-0 animate-spin" />
                        AI is drafting the combined entry from all{' '}
                        {sourceCount} sources…
                    </div>
                )}

                {preview && form.values && !form.aiPending && (
                    <>
                        <EvidenceWizard
                            step={step}
                            onStepChange={setStep}
                            values={form.values}
                            onChange={patchValues}
                            reference={reference}
                            errors={errors}
                            processing={processing}
                            primaryLabel="Merge into one entry"
                            onPrimary={merge}
                            footerExtras={
                                <Button
                                    variant="ghost"
                                    onClick={onClose}
                                    disabled={processing}
                                    className="text-stone-500"
                                >
                                    Cancel
                                </Button>
                            }
                        />
                        {errors.pii && (
                            <p className="text-[12.5px] font-semibold text-red-600">
                                {errors.pii}
                            </p>
                        )}
                        {confirmingSave && (
                            <ApproveConfirmDialog
                                files={keepableFiles}
                                flags={gatedSources.flatMap(
                                    (s) => s.pii_flags ?? [],
                                )}
                                flagLocation={
                                    gatedSources.length > 0 &&
                                    keepableFiles.length === 0
                                        ? 'the evidence being merged'
                                        : undefined
                                }
                                verb="Merge"
                                processing={processing}
                                onConfirm={(keepIds, piiAck) =>
                                    submitMerge(
                                        keepIds,
                                        piiAck
                                            ? gatedSources.map((s) => s.id)
                                            : [],
                                    )
                                }
                                onRemoveInfo={
                                    gatedSources.length > 0 &&
                                    keepableFiles.length === 0
                                        ? removeInfoAndMerge
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
        // Merging keeps takeaways per-source on the server; the combined
        // entry starts without its own lists (revealed so add-your-own works).
        nuggets: [],
        actions: [],
        source_notes: '',
        selected_takeaway_ids: [],
        reflection: d.reflection,
        category_slugs: d.category_slugs,
        domain_codes: d.domain_codes,
        attribute_codes: d.attribute_codes,
        project_ids: d.project_ids,
    };
}
