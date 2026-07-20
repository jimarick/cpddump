import { ChevronLeft, ChevronRight, Loader2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import {
    CategorisationStepFields,
    DetailsStepFields,
    initialTalkState,
    ReflectionStepFields,
} from '@/components/cpd/evidence-form-fields';
import type {
    EvidenceFormValues,
    ReflectionTalkState,
} from '@/components/cpd/evidence-form-fields';
import { Button } from '@/components/ui/button';
import type { ReferenceData } from '@/types/cpd';

/**
 * The linear 3-step editor shared by the inbox review modal and the merge
 * dialog: Details → Reflection → Categorise, no step tabs — movement is
 * Next/Back only, with the primary action on the last step. The parent owns
 * the step state so it can show "N of 3" in its header and jump to the
 * offending step on server validation errors (via stepForErrors).
 */
export const WIZARD_STEP_COUNT = 3;

/** Which wizard step a set of server-side validation errors belongs to. */
export function stepForErrors(errors: Record<string, string>): number {
    const keys = Object.keys(errors);

    if (keys.some((k) => k.startsWith('reflection'))) {
        return 1;
    }

    if (
        keys.some(
            (k) =>
                k.startsWith('category_slugs') ||
                k.startsWith('domain_codes') ||
                k.startsWith('attribute_codes'),
        )
    ) {
        return 2;
    }

    return 0;
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
    /** Rendered under the Details step fields (e.g. the missing-info note). */
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
}) {
    const lastStep = step === WIZARD_STEP_COUNT - 1;
    const stepProps = { values, onChange, reference, errors };

    // Owned here, not by the step: a dictated ramble survives Back/Next.
    const [talk, setTalk] = useState<ReflectionTalkState>(() =>
        initialTalkState(values, reference),
    );

    return (
        <>
            {step === 0 && (
                <>
                    <DetailsStepFields {...stepProps} hideTitle={hideTitle} />
                    {detailsExtra}
                </>
            )}
            {step === 1 && (
                <ReflectionStepFields
                    {...stepProps}
                    talk={talk}
                    onTalk={(patch) => setTalk((t) => ({ ...t, ...patch }))}
                    reflectionSource={reflectionSource}
                />
            )}
            {step === 2 && <CategorisationStepFields {...stepProps} />}

            <div className="mt-2 grid gap-3 border-t border-dashed border-stone-300 pt-4">
                {lastStep && lastStepExtra}
                <div className="flex flex-wrap items-center gap-2">
                    {step > 0 && (
                        <Button
                            variant="outline"
                            onClick={() => onStepChange(step - 1)}
                            disabled={processing}
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
                                onClick={() => onStepChange(step + 1)}
                                disabled={processing}
                                className="border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
                            >
                                Next <ChevronRight className="size-4" />
                            </Button>
                        ) : (
                            <Button
                                onClick={onPrimary}
                                disabled={processing}
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
