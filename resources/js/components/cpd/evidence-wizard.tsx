import { ChevronLeft, ChevronRight, Loader2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Sparkle } from '@/components/brand/sparkle';
import {
    ComposedStepFields,
    DetailsStepFields,
    NotesStepFields,
} from '@/components/cpd/evidence-form-fields';
import type { EvidenceFormValues } from '@/components/cpd/evidence-form-fields';
import { TakeawaysStepFields } from '@/components/cpd/takeaways-fields';
import { Button } from '@/components/ui/button';
import { postJson } from '@/lib/api';
import type { ReferenceData, Takeaway } from '@/types/cpd';

/**
 * The linear 4-step review editor shared by the inbox review modal and the
 * merge dialog: Facts → Your notes → Details & reflections → Takeaways.
 * No step tabs — movement is Next/Back only, with the primary action on the
 * last step. Leaving the notes page with changed notes runs the one AI pass
 * (details, reflections, takeaways, categories) behind a working state.
 * Categorisation has no page: the AI's filing is submitted silently and
 * stays editable from the activity's edit form. The parent owns the step
 * state so it can show "N of 4" in its header and jump to the offending
 * step on server validation errors (via stepForErrors).
 */
export const WIZARD_STEP_COUNT = 4;

/** Which wizard step a set of server-side validation errors belongs to. */
export function stepForErrors(errors: Record<string, string>): number {
    const keys = Object.keys(errors);

    if (keys.some((k) => k.startsWith('source_notes'))) {
        return 1;
    }

    if (
        keys.some((k) => k.startsWith('reflection') || k.startsWith('summary'))
    ) {
        return 2;
    }

    if (keys.some((k) => k.startsWith('nuggets') || k.startsWith('actions'))) {
        return 3;
    }

    return 0;
}

interface ComposedResponse {
    details: string;
    reflection: Record<string, string | null>;
    nuggets: Takeaway[];
    actions: Takeaway[];
    category_slugs: string[];
    domain_codes: string[];
    attribute_codes: string[];
}

export function EvidenceWizard({
    step,
    onStepChange,
    values,
    onChange,
    reference,
    errors = {},
    processing = false,
    primaryLabel,
    onPrimary,
    detailsExtra,
    lastStepExtra,
    footerExtras,
    footerRight,
    hideTitle = false,
    reflectionSource,
    initialComposedNotes = null,
}: {
    step: number;
    onStepChange: (step: number) => void;
    values: EvidenceFormValues;
    onChange: (patch: Partial<EvidenceFormValues>) => void;
    reference: ReferenceData;
    errors?: Record<string, string>;
    processing?: boolean;
    primaryLabel: ReactNode;
    onPrimary: () => void;
    /** Rendered under the Facts step fields (e.g. the missing-info note). */
    detailsExtra?: ReactNode;
    /** Rendered above the footer on the last step (e.g. the never-again checkbox). */
    lastStepExtra?: ReactNode;
    /** Ghost actions on the footer's left (Bin it, Merge with…, Cancel). */
    footerExtras?: ReactNode;
    /** Small print on the footer's right (e.g. AI confidence). */
    footerRight?: ReactNode;
    /** The parent shows the title in its own header instead. */
    hideTitle?: boolean;
    /** The analyst's note on where a pre-filled reflection came from. */
    reflectionSource?: string | null;
    /**
     * Notes the import analysis already processed — Next skips the AI pass
     * while the box still matches these. Null means never processed, so
     * any non-empty notes trigger a pass.
     */
    initialComposedNotes?: string | null;
}) {
    const lastStep = step === WIZARD_STEP_COUNT - 1;
    const stepProps = { values, onChange, reference, errors };

    const [lastComposed, setLastComposed] = useState<string | null>(
        initialComposedNotes === null ? null : initialComposedNotes.trim(),
    );
    const [composing, setComposing] = useState(false);

    const compose = async () => {
        const notes = values.source_notes.trim();

        setComposing(true);

        try {
            const composed = await postJson<ComposedResponse>(
                '/ai/compose-review',
                {
                    notes,
                    title: values.title || null,
                    activity_type_slug: values.activity_type_slug || null,
                    starts_on: values.starts_on || null,
                    organisation: values.organisation || null,
                    cpd_points: Number(values.cpd_points) || null,
                },
            );

            onChange({
                summary: composed.details,
                reflection: Object.fromEntries(
                    Object.entries(composed.reflection).map(([key, text]) => [
                        key,
                        text ?? '',
                    ]),
                ),
                nuggets: composed.nuggets,
                actions: composed.actions,
                // Fresh suggestions, fresh (empty) selection.
                selected_takeaway_ids: [],
                category_slugs: composed.category_slugs,
                domain_codes: composed.domain_codes,
                attribute_codes: composed.attribute_codes,
            });
            setLastComposed(notes);
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'The AI could not process your notes just now. Try again.',
            );
        } finally {
            setComposing(false);
        }
    };

    const next = () => {
        // Unchanged notes skip straight through — no re-processing.
        const notes = values.source_notes.trim();

        if (step === 1 && notes !== '' && notes !== lastComposed) {
            onStepChange(2);
            void compose();

            return;
        }

        onStepChange(step + 1);
    };

    const busy = processing || composing;

    return (
        <>
            {/* The one scrolling region — the footer below stays pinned. */}
            <div className="-mx-6 min-h-0 grow overflow-y-auto px-6 py-1">
                {step === 0 && (
                    <>
                        <DetailsStepFields
                            {...stepProps}
                            hideTitle={hideTitle}
                            hideSummary
                            singleColumn
                        />
                        {detailsExtra}
                    </>
                )}
                {step === 1 && <NotesStepFields {...stepProps} />}
                {step === 2 &&
                    (composing ? (
                        <AiWorkingState />
                    ) : (
                        <ComposedStepFields
                            {...stepProps}
                            reflectionSource={reflectionSource}
                        />
                    ))}
                {step === 3 && (
                    <TakeawaysStepFields values={values} onChange={onChange} />
                )}
            </div>

            <div className="grid shrink-0 gap-3 border-t border-dashed border-stone-300 pt-4">
                {lastStep && lastStepExtra}
                <div className="relative flex flex-wrap items-center gap-2">
                    {step > 0 && (
                        <Button
                            variant="outline"
                            onClick={() => onStepChange(step - 1)}
                            disabled={busy}
                            className="border-2 border-ink"
                        >
                            <ChevronLeft className="size-4" /> Back
                        </Button>
                    )}
                    {footerExtras}
                    <span className="ml-auto flex items-center gap-3">
                        {footerRight}
                        {!lastStep ? (
                            <Button
                                onClick={next}
                                disabled={busy}
                                className="border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
                            >
                                Next <ChevronRight className="size-4" />
                            </Button>
                        ) : (
                            <Button
                                onClick={onPrimary}
                                disabled={busy}
                                className="border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
                            >
                                {processing && (
                                    <Loader2 className="size-4 animate-spin" />
                                )}{' '}
                                {primaryLabel}
                            </Button>
                        )}
                    </span>
                </div>
            </div>
        </>
    );
}

const WORKING_LINES = [
    'Reading your notes…',
    'Shaping your reflections…',
    'Panning for nuggets…',
];

/** The boxes stay hidden while the AI works; then they appear, filled. */
function AiWorkingState() {
    const [line, setLine] = useState(0);

    useEffect(() => {
        const timer = setInterval(
            () => setLine((l) => (l + 1) % WORKING_LINES.length),
            2000,
        );

        return () => clearInterval(timer);
    }, []);

    return (
        <div className="flex min-h-[280px] flex-col items-center justify-center gap-1 text-center">
            <div className="relative flex size-28 items-center justify-center">
                <div className="absolute inset-0 animate-spin [animation-duration:2.8s] motion-reduce:animate-none">
                    <span className="absolute top-0 left-1/2 size-2 -translate-x-1/2 rounded-full bg-brand/60" />
                    <span className="absolute bottom-2 left-2 size-1.5 rounded-full bg-brand-dark/50" />
                    <span className="absolute right-1 bottom-6 size-1.5 rounded-full bg-brand/30" />
                </div>
                <Sparkle
                    size={38}
                    className="animate-pulse text-brand motion-reduce:animate-none"
                />
            </div>
            <p
                className="mt-3 text-[15.5px] font-bold"
                role="status"
                aria-live="polite"
            >
                {WORKING_LINES[line]}
            </p>
            <p className="text-[12.5px] text-stone-400">
                Your words stay yours — the AI only tidies and files.
            </p>
            <div className="mt-5 grid w-3/4 gap-2.5">
                <div className="h-2.5 w-full animate-pulse rounded-full bg-brand-tint" />
                <div className="h-2.5 w-5/6 animate-pulse rounded-full bg-paper-alt [animation-delay:200ms]" />
                <div className="h-2.5 w-3/5 animate-pulse rounded-full bg-brand-tint [animation-delay:400ms]" />
            </div>
        </div>
    );
}
