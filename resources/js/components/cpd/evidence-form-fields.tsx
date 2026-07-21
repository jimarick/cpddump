import { Info, Loader2, Mic, Square } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Sparkle } from '@/components/brand/sparkle';
import { AiTextarea } from '@/components/cpd/ai-textarea';
import {
    DictatedInput,
    DictatedTextarea,
} from '@/components/cpd/dictated-fields';
import { DictationButton } from '@/components/cpd/dictation-button';
import { Button } from '@/components/ui/button';
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
import { useDictation } from '@/hooks/use-dictation';
import { postJson } from '@/lib/api';
import { cn } from '@/lib/utils';
import type { ReferenceData, ReflectionPrompt, Takeaway } from '@/types/cpd';

/**
 * The editable draft-activity fields shared by the inbox review modal, the
 * merge dialog and the activity edit dialog. Purely controlled: parent owns
 * the values. Exposed as three step sections (details / reflection /
 * categorisation) for the EvidenceWizard; EvidenceFormFields stacks them
 * for the single-scroll edit form.
 *
 * `organisation` has no input any more but MUST stay in the values and in
 * every submit payload: ActivityController@update nulls the column when the
 * key is absent, and the AI-extracted value still feeds exports and merges.
 */
export interface EvidenceFormValues {
    title: string;
    activity_type_slug: string;
    starts_on: string;
    ends_on: string;
    organisation: string;
    cpd_points: number | string;
    summary: string;
    nuggets: Takeaway[];
    actions: Takeaway[];
    source_notes: string;
    /**
     * Client-side only: takeaways are opt-in per item — the AI's
     * suggestions all start deselected, and approve keeps only the
     * selected ids (the rest are discarded, never recorded).
     */
    selected_takeaway_ids: string[];
    reflection: Record<string, string>;
    category_slugs: string[];
    domain_codes: string[];
    attribute_codes: string[];
    project_ids: number[];
}

interface StepProps {
    values: EvidenceFormValues;
    onChange: (patch: Partial<EvidenceFormValues>) => void;
    reference: ReferenceData;
    errors?: Record<string, string>;
}

/** A short grounding blurb about the activity, passed to the AI assist. */
function aiContext(values: EvidenceFormValues): string {
    const openTexts = (items: Takeaway[]) =>
        items.filter((t) => !t.done).map((t) => `- ${t.text}`);

    const nuggets = openTexts(values.nuggets ?? []);
    const actions = openTexts(values.actions ?? []);

    return [
        values.title && `Activity: ${values.title}`,
        values.activity_type_slug && `Type: ${values.activity_type_slug}`,
        values.starts_on && `Date: ${values.starts_on}`,
        values.organisation && `Organisation: ${values.organisation}`,
        values.summary && `Details: ${values.summary}`,
        nuggets.length > 0 &&
            `Key learning points (the user's own):\n${nuggets.join('\n')}`,
        actions.length > 0 && `Action points:\n${actions.join('\n')}`,
    ]
        .filter(Boolean)
        .join('\n');
}

const NO_PROJECT = 'none';

/**
 * Quiet field labels: every field arrives AI-prefilled, so the values carry
 * the meaning — labels whisper rather than head their fields.
 */
const LABEL_QUIET = 'text-[11px] font-bold tracking-wide text-stone-400 uppercase';

export function DetailsStepFields({
    values,
    onChange,
    reference,
    errors = {},
    hideTitle = false,
    hideSummary = false,
    singleColumn = false,
}: StepProps & {
    /** The review modal shows the title in its header instead. */
    hideTitle?: boolean;
    /** The review wizard moves the details prose to its own later step. */
    hideSummary?: boolean;
    /** The review wizard's facts page: one field per line, end date always shown. */
    singleColumn?: boolean;
}) {
    const [showEnd, setShowEnd] = useState(
        () => Boolean(values.ends_on) && values.ends_on !== values.starts_on,
    );
    const endVisible = showEnd || Boolean(errors.ends_on);

    if (singleColumn) {
        return (
            <div className="grid gap-5">
                <div className="grid gap-1.5">
                    <Label className={LABEL_QUIET} htmlFor="starts_on">
                        Dates
                    </Label>
                    <div className="flex items-center gap-2">
                        <Input
                            id="starts_on"
                            type="date"
                            value={values.starts_on}
                            onChange={(e) =>
                                onChange({ starts_on: e.target.value })
                            }
                        />
                        <span className="text-stone-400">→</span>
                        <Input
                            id="ends_on"
                            type="date"
                            aria-label="End date"
                            value={values.ends_on}
                            onChange={(e) =>
                                onChange({ ends_on: e.target.value })
                            }
                        />
                    </div>
                    <FieldError message={errors.starts_on ?? errors.ends_on} />
                </div>

                <div className="grid gap-1.5">
                    <Label className={LABEL_QUIET}>Type</Label>
                    <TypeSelect values={values} onChange={onChange} reference={reference} />
                    <FieldError message={errors.activity_type_slug} />
                </div>

                <div className="grid gap-1.5">
                    <Label className={LABEL_QUIET}>Project / learning goal</Label>
                    <ProjectSelect values={values} onChange={onChange} reference={reference} />
                    <FieldError message={errors.project_ids} />
                </div>

                <div className="grid gap-1.5">
                    <span className="flex items-center gap-1.5">
                        <Label className={LABEL_QUIET} htmlFor="cpd_points">
                            CPD points
                        </Label>
                        <CpdPointsInfo />
                    </span>
                    <Input
                        id="cpd_points"
                        type="number"
                        min={0}
                        step={0.5}
                        value={values.cpd_points}
                        onChange={(e) =>
                            onChange({ cpd_points: e.target.value })
                        }
                        className="w-32"
                    />
                    <FieldError message={errors.cpd_points} />
                </div>
            </div>
        );
    }

    return (
        <div className="grid gap-5">
            {!hideTitle && (
                <div className="grid gap-1.5">
                    <Label className={LABEL_QUIET} htmlFor="title">
                        Title
                    </Label>
                    <DictatedInput
                        id="title"
                        value={values.title}
                        onValueChange={(title) => onChange({ title })}
                    />
                    <FieldError message={errors.title} />
                </div>
            )}

            <div className="grid grid-cols-[1fr_6rem] gap-4">
                <div className="grid gap-1.5">
                    <div className="flex items-center gap-1.5">
                        <Label className={LABEL_QUIET} htmlFor="starts_on">
                            {endVisible ? 'Dates' : 'Date'}
                        </Label>
                        {!endVisible && (
                            <button
                                type="button"
                                onClick={() => setShowEnd(true)}
                                className="cursor-pointer text-xs text-stone-500 underline decoration-dashed underline-offset-3 hover:text-ink"
                            >
                                · + end
                            </button>
                        )}
                    </div>
                    <div className="flex items-center gap-2">
                        <Input
                            id="starts_on"
                            type="date"
                            value={values.starts_on}
                            onChange={(e) =>
                                onChange({ starts_on: e.target.value })
                            }
                        />
                        {endVisible && (
                            <>
                                <span className="text-stone-400">→</span>
                                <Input
                                    id="ends_on"
                                    type="date"
                                    aria-label="End date"
                                    value={values.ends_on}
                                    onChange={(e) =>
                                        onChange({ ends_on: e.target.value })
                                    }
                                />
                            </>
                        )}
                    </div>
                    <FieldError message={errors.starts_on ?? errors.ends_on} />
                </div>
                <div className="grid gap-1.5">
                    <span className="flex items-center gap-1.5">
                        <Label className={LABEL_QUIET} htmlFor="cpd_points">Points</Label>
                        <CpdPointsInfo />
                    </span>
                    <Input
                        id="cpd_points"
                        type="number"
                        min={0}
                        step={0.5}
                        value={values.cpd_points}
                        onChange={(e) =>
                            onChange({ cpd_points: e.target.value })
                        }
                    />
                    <FieldError message={errors.cpd_points} />
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-1.5">
                    <Label className={LABEL_QUIET}>Type</Label>
                    <TypeSelect values={values} onChange={onChange} reference={reference} />
                    <FieldError message={errors.activity_type_slug} />
                </div>
                <div className="grid gap-1.5">
                    <Label className={LABEL_QUIET}>Project / learning goal</Label>
                    <ProjectSelect values={values} onChange={onChange} reference={reference} />
                    <FieldError message={errors.project_ids} />
                </div>
            </div>

            {!hideSummary && (
                <div className="grid gap-1.5">
                    <Label className={LABEL_QUIET} htmlFor="summary">Details</Label>
                    <AiTextarea
                        id="summary"
                        value={values.summary}
                        rows={7}
                        onChange={(v) => onChange({ summary: v })}
                        field="Details — a first-person account of what this activity was, as if the user wrote it"
                        context={aiContext({ ...values, summary: '' })}
                    />
                </div>
            )}
        </div>
    );
}

/**
 * The "how many points?" reference, condensed from the UK royal colleges'
 * AoMRC-aligned CPD schemes (RCR, RCP, RCPath, RCOG). Guidance, not rules —
 * schemes differ, so it always points back to the user's own college.
 */
export function CpdPointsInfo() {
    const [open, setOpen] = useState(false);

    return (
        <>
            <button
                type="button"
                aria-label="How to estimate CPD points"
                onClick={() => setOpen(true)}
                className="cursor-pointer text-stone-400 hover:text-ink"
            >
                <Info className="size-3.5" />
            </button>
            {open && (
                <Dialog open onOpenChange={(o) => !o && setOpen(false)}>
                    <DialogContent className="max-w-md">
                        <DialogHeader>
                            <DialogTitle className="font-display text-xl font-extrabold">
                                Estimating CPD points
                            </DialogTitle>
                        </DialogHeader>
                        <div className="grid gap-3 text-[13.5px] leading-relaxed text-stone-700">
                            <p>
                                <b>1 point ≈ 1 hour</b> of active learning —
                                the convention agreed across the UK royal
                                colleges. Count the hours you were actually
                                engaged, not the advertised maximum.
                            </p>
                            <p>
                                <b>Reflection can earn more.</b> Most colleges
                                award extra credit for documented reflection —
                                typically 1 additional point per written
                                reflective note (RCPath, RCOG), and the RCR
                                invites additional credits for reflecting on
                                the impact on your practice.
                            </p>
                            <p>
                                <b>Some activities have set values</b> (they
                                vary by college): a clinical audit or QI
                                project up to ~5 points; a presentation or
                                poster ~1–2; writing a journal article up to
                                ~6; teaching often capped around 10–12 points
                                a year.
                            </p>
                            <p>
                                <b>The year should add up to ~50</b> — the
                                usual target is 50 points a year, 250 across
                                the 5-year revalidation cycle.
                            </p>
                            <p className="border-t border-dashed border-stone-300 pt-3 text-[12px] text-stone-500">
                                Schemes differ in the fine print — when it
                                matters, check your own college's CPD
                                guidance. This is a reference, not a rule
                                book.
                            </p>
                        </div>
                    </DialogContent>
                </Dialog>
            )}
        </>
    );
}

function TypeSelect({
    values,
    onChange,
    reference,
}: Pick<StepProps, 'values' | 'onChange' | 'reference'>) {
    return (
        <Select
            value={values.activity_type_slug}
            onValueChange={(v) => onChange({ activity_type_slug: v })}
        >
            <SelectTrigger className="w-full">
                <SelectValue placeholder="Type" />
            </SelectTrigger>
            <SelectContent>
                {reference.activityTypes.map((t) => (
                    <SelectItem key={t.slug} value={t.slug}>
                        <span className="flex items-center gap-2">
                            <span
                                className="size-2.5 rounded-full"
                                style={{ backgroundColor: t.color }}
                            />
                            {t.name}
                        </span>
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function ProjectSelect({
    values,
    onChange,
    reference,
}: Pick<StepProps, 'values' | 'onChange' | 'reference'>) {
    return (
        <Select
            value={
                values.project_ids[0] !== undefined
                    ? String(values.project_ids[0])
                    : NO_PROJECT
            }
            onValueChange={(v) =>
                onChange({ project_ids: v === NO_PROJECT ? [] : [Number(v)] })
            }
        >
            <SelectTrigger className="w-full">
                <SelectValue placeholder="None" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={NO_PROJECT}>
                    <span className="text-stone-500">None</span>
                </SelectItem>
                {reference.projects.map((p) => (
                    <SelectItem key={p.id} value={String(p.id)}>
                        {p.title}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/**
 * The wizard's notes page: one big box for everything the user wrote or
 * said about the event, prefilled verbatim from the dump. Next runs the
 * AI pass — the handwritten note is the only explanation it needs.
 */
export function NotesStepFields({
    values,
    onChange,
    errors = {},
}: Omit<StepProps, 'reference'> & { errors?: Record<string, string> }) {
    return (
        <div className="grid gap-1.5">
            <Label className={LABEL_QUIET} htmlFor="source_notes">
                Your notes &amp; reflections — dump it all in
            </Label>
            <DictatedTextarea
                id="source_notes"
                value={values.source_notes}
                onValueChange={(source_notes) => onChange({ source_notes })}
                rows={9}
                placeholder="Everything you typed, pasted or want to say about it — rough is fine. Talk, type or paste."
            />
            <FieldError message={errors.source_notes} />
            <div className="mt-1 flex items-start gap-1.5">
                <p className="rotate-[-0.7deg] font-hand text-[19px] leading-snug text-brand-dark">
                    Dictate or type — AI will turn this into reflections and
                    key takeaways in the next step
                </p>
                {/* Curvy hand-drawn arrow from the note up into the box —
                    positioned + z-raised so it rides over the box border. */}
                <svg
                    viewBox="0 0 40 46"
                    aria-hidden="true"
                    className="relative z-10 -mt-8 size-11 shrink-0 text-brand-dark"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="2.2"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                >
                    <path d="M4 42 C 24 40, 35 27, 31 8" />
                    <path d="M24 15 L31 6 L37 14" />
                </svg>
            </div>
        </div>
    );
}

/**
 * The wizard's write-up page: the two-sentence details prose plus the
 * profession's reflection answers, all AI-drafted from the notes page and
 * all editable.
 */
export function ComposedStepFields({
    values,
    onChange,
    reference,
    errors = {},
    reflectionSource,
}: StepProps & { reflectionSource?: string | null }) {
    return (
        <div className="grid gap-5">
            <div className="grid gap-1.5">
                <Label className={LABEL_QUIET} htmlFor="summary">Details</Label>
                <AiTextarea
                    id="summary"
                    value={values.summary}
                    rows={3}
                    onChange={(v) => onChange({ summary: v })}
                    field="Details — a first-person account of what this activity was, two sentences maximum"
                    context={aiContext({ ...values, summary: '' })}
                />
                <FieldError message={errors.summary} />
            </div>
            <ReflectionStepFields
                values={values}
                onChange={onChange}
                reference={reference}
                errors={errors}
                reflectionSource={reflectionSource}
            />
        </div>
    );
}

/**
 * Cross-step state for the talk-first reflection capture. Owned by the
 * wizard / stacked wrapper (which stays mounted across step changes),
 * never by the step itself — a dictated ramble must survive Back/Next.
 */
export interface ReflectionTalkState {
    /** talk = the single capture box; boxes = the per-prompt fields. */
    mode: 'talk' | 'boxes';
    ramble: string;
    /** True once the ramble has been AI-shaped into the boxes. */
    shaped: boolean;
}

/** Talk-first when the AI (honestly) left every reflection empty. */
export function initialTalkState(
    values: EvidenceFormValues,
    reference: ReferenceData,
): ReflectionTalkState {
    const prompts = reference.reflectionPrompts;
    const allEmpty =
        prompts.length > 0 &&
        prompts.every((p) => !(values.reflection[p.key] ?? '').trim());

    return { mode: allEmpty ? 'talk' : 'boxes', ramble: '', shaped: false };
}

export function ReflectionStepFields({
    values,
    onChange,
    reference,
    talk,
    onTalk,
    reflectionSource,
}: StepProps & {
    talk?: ReflectionTalkState;
    onTalk?: (patch: Partial<ReflectionTalkState>) => void;
    /** The analyst's note on where a pre-filled reflection came from. */
    reflectionSource?: string | null;
}) {
    const [openInfo, setOpenInfo] = useState<Record<string, boolean>>({});

    if (
        talk &&
        onTalk &&
        talk.mode === 'talk' &&
        reference.reflectionPrompts.length > 0
    ) {
        return (
            <TalkFirstCapture
                values={values}
                onChange={onChange}
                reference={reference}
                talk={talk}
                onTalk={onTalk}
            />
        );
    }

    /**
     * Grounding for a per-box sparkle redraft: the activity details, the
     * other boxes' answers, and the original ramble — so a regenerate
     * stays consistent with (and true to) the rest of the reflection.
     */
    const boxContext = (currentKey: string) =>
        [
            aiContext(values),
            ...reference.reflectionPrompts
                .filter(
                    (p) =>
                        p.key !== currentKey &&
                        (values.reflection[p.key] ?? '').trim(),
                )
                .map((p) => `${p.label}: ${values.reflection[p.key]}`),
            talk?.ramble.trim()
                ? `The user's own reflection notes:\n${talk.ramble.trim()}`
                : '',
        ]
            .filter(Boolean)
            .join('\n')
            .slice(0, 4000);

    const provenance = talk?.shaped
        ? 'Shaped from your dictation — edit anything, or tap a sparkle to redo one box.'
        : reflectionSource;

    return (
        <div className="grid gap-5">
            {provenance &&
                reference.reflectionPrompts.some((p) =>
                    (values.reflection[p.key] ?? '').trim(),
                ) && (
                    <div className="flex items-start gap-2 rounded-[8px] border border-dashed border-brand bg-brand-pale px-3 py-2 text-xs text-brand-dark">
                        <Sparkle size={11} className="mt-0.5 shrink-0" />
                        <span>{provenance}</span>
                    </div>
                )}
            {reference.reflectionPrompts.map((prompt: ReflectionPrompt) => (
                <div key={prompt.key} className="grid gap-1.5">
                    <div className="flex items-center gap-1.5">
                        <Label className={LABEL_QUIET} htmlFor={`reflection-${prompt.key}`}>
                            {prompt.label}
                        </Label>
                        <button
                            type="button"
                            aria-label={`What "${prompt.label}" means`}
                            aria-expanded={Boolean(openInfo[prompt.key])}
                            onClick={() =>
                                setOpenInfo((open) => ({
                                    ...open,
                                    [prompt.key]: !open[prompt.key],
                                }))
                            }
                            className={cn(
                                'cursor-pointer',
                                openInfo[prompt.key]
                                    ? 'text-brand'
                                    : 'text-stone-400 hover:text-ink',
                            )}
                        >
                            <Info className="size-3.5" />
                        </button>
                    </div>
                    {openInfo[prompt.key] && (
                        <p className="rounded-lg bg-paper px-3 py-2 text-xs text-pretty text-stone-500">
                            {prompt.question}
                        </p>
                    )}
                    <AiTextarea
                        id={`reflection-${prompt.key}`}
                        value={values.reflection[prompt.key] ?? ''}
                        rows={5}
                        placeholder="Not covered yet — dictate, type, or tap the sparkle to draft it."
                        onChange={(v) =>
                            onChange({
                                reflection: {
                                    ...values.reflection,
                                    [prompt.key]: v,
                                },
                            })
                        }
                        field={`Appraisal reflection — ${prompt.label}: ${prompt.question}`}
                        context={boxContext(prompt.key)}
                    />
                </div>
            ))}
            {reference.reflectionPrompts.length === 0 && (
                <p className="text-sm text-stone-500">
                    No reflection prompts for your profession.
                </p>
            )}
        </div>
    );
}

/**
 * The talk-first capture: one box, the profession's questions stated up
 * front, a big mic. Once there's a ramble, "shape into reflections" sends
 * it (plus activity context) to /ai/reflection-draft and flips the step
 * to the per-prompt boxes, filled only where the ramble supports them.
 */
function TalkFirstCapture({
    values,
    onChange,
    reference,
    talk,
    onTalk,
}: {
    values: EvidenceFormValues;
    onChange: (patch: Partial<EvidenceFormValues>) => void;
    reference: ReferenceData;
    talk: ReflectionTalkState;
    onTalk: (patch: Partial<ReflectionTalkState>) => void;
}) {
    const prompts = reference.reflectionPrompts;
    const [typing, setTyping] = useState(false);
    const [shaping, setShaping] = useState(false);

    const appendTranscript = (text: string) =>
        onTalk({
            ramble: talk.ramble.trim() ? `${talk.ramble.trim()} ${text}` : text,
        });

    const dictation = useDictation(appendTranscript);

    const hasText = talk.ramble.trim() !== '';
    const boxVisible = hasText || typing;

    const shape = async () => {
        setShaping(true);

        try {
            const result = await postJson<{
                reflection: Record<string, string | null>;
            }>('/ai/reflection-draft', {
                text: talk.ramble,
                context: aiContext(values).slice(0, 4000),
            });

            const reflection = { ...values.reflection };

            for (const prompt of prompts) {
                reflection[prompt.key] = result.reflection[prompt.key] ?? '';
            }

            onChange({ reflection });
            onTalk({ mode: 'boxes', shaped: true });
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'The AI could not help just now.',
            );
        } finally {
            setShaping(false);
        }
    };

    return (
        <div className="grid gap-4">
            <div className="grid gap-1.5">
                <Label className={LABEL_QUIET}>Reflection</Label>
                <h3 className="font-display text-[22px] font-extrabold tracking-[-0.015em]">
                    Talk it through.
                </h3>
                <ul className="grid gap-1">
                    {prompts.map((prompt, i) => (
                        <li
                            key={prompt.key}
                            className="flex gap-2 text-[13.5px] text-stone-600"
                        >
                            <span className="font-display font-extrabold text-brand">
                                {i + 1}
                            </span>
                            {prompt.label}
                        </li>
                    ))}
                </ul>
            </div>

            {!boxVisible ? (
                <div className="grid min-h-[190px] place-content-center justify-items-center gap-3 rounded-[14px] border-2 border-dashed border-stone-400 bg-paper p-6 text-center">
                    <button
                        type="button"
                        onClick={dictation.toggle}
                        disabled={dictation.transcribing}
                        title={
                            dictation.recording
                                ? 'Stop and transcribe'
                                : 'Dictate'
                        }
                        className={cn(
                            'flex size-16 cursor-pointer items-center justify-center rounded-full border-2 border-ink text-white shadow-[3px_3px_0_#1c1917] transition-colors disabled:opacity-60',
                            dictation.recording
                                ? 'animate-pulse bg-red-500'
                                : 'bg-brand hover:bg-brand-dark',
                        )}
                    >
                        {dictation.transcribing ? (
                            <Loader2 className="size-6 animate-spin" />
                        ) : dictation.recording ? (
                            <Square className="size-5" />
                        ) : (
                            <Mic className="size-6" />
                        )}
                    </button>
                    {dictation.recording || dictation.transcribing ? (
                        <p className="max-w-[38ch] text-[12.5px] text-stone-500">
                            {dictation.transcribing
                                ? 'Tidying up…'
                                : dictation.preview || 'Listening…'}
                        </p>
                    ) : (
                        <p className="max-w-[34ch] text-[13px] text-pretty text-stone-500">
                            <span className="font-semibold text-ink">
                                Tap to talk
                            </span>{' '}
                            — a minute of honest rambling is plenty.{' '}
                            <button
                                type="button"
                                onClick={() => setTyping(true)}
                                className="cursor-pointer underline decoration-dashed underline-offset-3 hover:text-ink"
                            >
                                Typing works too
                            </button>
                            .
                        </p>
                    )}
                </div>
            ) : (
                <div className="grid gap-3 rounded-[14px] border-2 border-ink bg-white p-4">
                    <textarea
                        value={talk.ramble}
                        rows={7}
                        placeholder="Why you picked it, what you took away, what might change…"
                        onChange={(e) => onTalk({ ramble: e.target.value })}
                        className="w-full resize-none border-none bg-transparent text-sm leading-relaxed focus-visible:outline-none"
                    />
                    <div className="flex flex-wrap items-center gap-2.5">
                        <Button
                            onClick={shape}
                            disabled={shaping || !hasText}
                            className="border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
                        >
                            {shaping ? (
                                <Loader2 className="size-4 animate-spin" />
                            ) : (
                                <Sparkle size={13} />
                            )}{' '}
                            Shape into {prompts.length} reflections
                        </Button>
                        <DictationButton onTranscript={appendTranscript} />
                    </div>
                </div>
            )}

            <button
                type="button"
                onClick={() => onTalk({ mode: 'boxes' })}
                className="justify-self-start cursor-pointer text-xs text-stone-500 underline decoration-dashed underline-offset-3 hover:text-ink"
            >
                or fill in the {prompts.length} boxes yourself
            </button>
        </div>
    );
}

export function CategorisationStepFields({
    values,
    onChange,
    reference,
}: StepProps) {
    const toggle = (list: string[], value: string) =>
        list.includes(value)
            ? list.filter((v) => v !== value)
            : [...list, value];

    return (
        <div className="grid gap-5">
            <div className="grid gap-2">
                <Label className={LABEL_QUIET}>Categories</Label>
                <div className="flex flex-wrap gap-1.5">
                    {reference.categories.map((c) => (
                        <TogglePill
                            key={c.slug}
                            label={c.name}
                            active={values.category_slugs.includes(c.slug)}
                            onClick={() =>
                                onChange({
                                    category_slugs: toggle(
                                        values.category_slugs,
                                        c.slug,
                                    ),
                                })
                            }
                        />
                    ))}
                </div>
            </div>

            <div className="grid gap-2">
                <Label className={LABEL_QUIET}>GMC domains &amp; attributes</Label>
                <div className="grid gap-2.5">
                    {reference.domains.map((domain) => {
                        const domainActive = values.domain_codes.includes(
                            domain.code,
                        );

                        return (
                            <div
                                key={domain.code}
                                className="rounded-[10px] border border-dashed border-stone-300 p-2.5"
                            >
                                <TogglePill
                                    label={`${domain.code.replace('D', 'Domain ')} — ${domain.name}`}
                                    active={domainActive}
                                    onClick={() =>
                                        onChange({
                                            domain_codes: toggle(
                                                values.domain_codes,
                                                domain.code,
                                            ),
                                        })
                                    }
                                    strong
                                />
                                {domainActive && (
                                    <div className="mt-2 flex flex-wrap gap-1.5">
                                        {domain.framework_attributes.map(
                                            (attr) => (
                                                <TogglePill
                                                    key={attr.code}
                                                    label={`${attr.code} ${attr.name}`}
                                                    active={values.attribute_codes.includes(
                                                        attr.code,
                                                    )}
                                                    onClick={() =>
                                                        onChange({
                                                            attribute_codes:
                                                                toggle(
                                                                    values.attribute_codes,
                                                                    attr.code,
                                                                ),
                                                        })
                                                    }
                                                />
                                            ),
                                        )}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

/** True when any server error belongs to the categorisation pickers. */
function hasCategorisationErrors(errors: Record<string, string>): boolean {
    return Object.keys(errors).some(
        (key) =>
            key.startsWith('category_slugs') ||
            key.startsWith('domain_codes') ||
            key.startsWith('attribute_codes'),
    );
}

/**
 * Condensed categorisation for the single-scroll edit form: the selected
 * chips on one row with an "edit" link that unfolds the full pickers.
 */
export function CategorisationSummary(props: StepProps) {
    const { values, reference, errors = {} } = props;
    const [open, setOpen] = useState(false);

    // Server errors on the pickers force the section open so they're seen.
    const expanded = open || hasCategorisationErrors(errors);

    const chips = [
        ...reference.categories
            .filter((c) => values.category_slugs.includes(c.slug))
            .map((c) => c.name),
        ...reference.domains
            .filter((d) => values.domain_codes.includes(d.code))
            .map((d) => {
                const attrCount = d.framework_attributes.filter((a) =>
                    values.attribute_codes.includes(a.code),
                ).length;
                const label = d.code.replace('D', 'Domain ');

                return attrCount > 0 ? `${label} · ${attrCount}` : label;
            }),
    ];

    if (expanded) {
        return (
            <div className="grid gap-3">
                <CategorisationStepFields {...props} />
                <button
                    type="button"
                    onClick={() => setOpen(false)}
                    className="justify-self-start text-xs text-stone-500 underline decoration-dashed underline-offset-3 hover:text-ink"
                >
                    done
                </button>
            </div>
        );
    }

    return (
        <div className="grid gap-2">
            <Label className={LABEL_QUIET}>Categorisation</Label>
            <div className="flex flex-wrap items-center gap-1.5">
                {chips.map((chip) => (
                    <span
                        key={chip}
                        className="rounded-full border border-brand bg-brand-tint px-2.5 py-1 text-xs font-semibold text-brand-dark"
                    >
                        {chip}
                    </span>
                ))}
                {chips.length === 0 && (
                    <span className="text-xs text-stone-400">
                        Nothing picked yet
                    </span>
                )}
                <button
                    type="button"
                    onClick={() => setOpen(true)}
                    className="text-xs text-stone-500 underline decoration-dashed underline-offset-3 hover:text-ink"
                >
                    edit
                </button>
            </div>
        </div>
    );
}

/** Stacked single-scroll form — used by the activity edit dialog. */
export function EvidenceFormFields(props: StepProps) {
    const [talk, setTalk] = useState<ReflectionTalkState>(() =>
        initialTalkState(props.values, props.reference),
    );

    return (
        <div className="grid gap-6">
            <DetailsStepFields {...props} hideSummary />
            <div className="border-t border-dashed border-stone-300 pt-5">
                <CategorisationSummary {...props} />
            </div>
            <div className="grid gap-5 border-t border-dashed border-stone-300 pt-5">
                <div className="text-sm font-bold">Reflection</div>
                <div className="grid gap-1.5">
                    <Label className={LABEL_QUIET} htmlFor="summary">
                        Details
                    </Label>
                    <AiTextarea
                        id="summary"
                        value={props.values.summary}
                        rows={4}
                        onChange={(v) => props.onChange({ summary: v })}
                        field="Details — a first-person account of what this activity was, two sentences maximum"
                        context={aiContext({ ...props.values, summary: '' })}
                    />
                </div>
                <ReflectionStepFields
                    {...props}
                    talk={talk}
                    onTalk={(patch) => setTalk((t) => ({ ...t, ...patch }))}
                />
            </div>
        </div>
    );
}

function FieldError({ message }: { message?: string }) {
    return message ? <p className="text-xs text-red-600">{message}</p> : null;
}

function TogglePill({
    label,
    active,
    onClick,
    strong = false,
}: {
    label: string;
    active: boolean;
    onClick: () => void;
    strong?: boolean;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'cursor-pointer rounded-full border px-2.5 py-1 text-left text-xs transition-colors',
                strong && 'font-semibold',
                active
                    ? 'border-brand bg-brand-tint font-semibold text-brand-dark'
                    : 'border-dashed border-stone-400 text-stone-600 hover:border-ink hover:text-ink',
            )}
        >
            {label}
        </button>
    );
}
