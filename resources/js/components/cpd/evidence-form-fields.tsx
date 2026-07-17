import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { ReferenceData, ReflectionPrompt } from '@/types/cpd';

/**
 * The editable draft-activity fields shared by the inbox review modal and
 * the activity edit dialog. Purely controlled: parent owns the values.
 * Exposed as three step sections (details / reflection / categorisation)
 * so the review modal can paginate; EvidenceFormFields stacks all three.
 */
export interface EvidenceFormValues {
    title: string;
    activity_type_slug: string;
    starts_on: string;
    ends_on: string;
    organisation: string;
    cpd_points: number | string;
    summary: string;
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

export function DetailsStepFields({
    values,
    onChange,
    reference,
    errors = {},
}: StepProps) {
    return (
        <div className="grid gap-5">
            <div className="grid gap-1.5">
                <Label htmlFor="title">Title</Label>
                <Input
                    id="title"
                    value={values.title}
                    onChange={(e) => onChange({ title: e.target.value })}
                />
                <FieldError message={errors.title} />
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-1.5">
                    <Label>Type</Label>
                    <Select
                        value={values.activity_type_slug}
                        onValueChange={(v) =>
                            onChange({ activity_type_slug: v })
                        }
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
                    <FieldError message={errors.activity_type_slug} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="cpd_points">CPD points</Label>
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
                    <Label htmlFor="starts_on">Start date</Label>
                    <Input
                        id="starts_on"
                        type="date"
                        value={values.starts_on}
                        onChange={(e) =>
                            onChange({ starts_on: e.target.value })
                        }
                    />
                    <FieldError message={errors.starts_on} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="ends_on">End date</Label>
                    <Input
                        id="ends_on"
                        type="date"
                        value={values.ends_on}
                        onChange={(e) => onChange({ ends_on: e.target.value })}
                    />
                    <FieldError message={errors.ends_on} />
                </div>
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="organisation">Organisation</Label>
                <Input
                    id="organisation"
                    value={values.organisation}
                    onChange={(e) => onChange({ organisation: e.target.value })}
                    placeholder="e.g. Royal College of Radiologists"
                />
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="summary">Details</Label>
                <Textarea
                    id="summary"
                    value={values.summary}
                    rows={4}
                    onChange={(v) => onChange({ summary: v })}
                />
            </div>
        </div>
    );
}

export function ReflectionStepFields({
    values,
    onChange,
    reference,
}: StepProps) {
    return (
        <div className="grid gap-5">
            {reference.reflectionPrompts.map((prompt: ReflectionPrompt) => (
                <div key={prompt.key} className="grid gap-1.5">
                    <Label htmlFor={`reflection-${prompt.key}`}>
                        {prompt.label}
                    </Label>
                    <p className="text-xs text-pretty text-stone-500">
                        {prompt.question}
                    </p>
                    <Textarea
                        id={`reflection-${prompt.key}`}
                        value={values.reflection[prompt.key] ?? ''}
                        rows={5}
                        onChange={(v) =>
                            onChange({
                                reflection: {
                                    ...values.reflection,
                                    [prompt.key]: v,
                                },
                            })
                        }
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

export function CategorisationStepFields({
    values,
    onChange,
    reference,
}: StepProps) {
    const toggle = (list: string[], value: string) =>
        list.includes(value)
            ? list.filter((v) => v !== value)
            : [...list, value];

    const toggleNumber = (list: number[], value: number) =>
        list.includes(value)
            ? list.filter((v) => v !== value)
            : [...list, value];

    return (
        <div className="grid gap-5">
            <div className="grid gap-2">
                <Label>Categories</Label>
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
                <Label>GMC domains &amp; attributes</Label>
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

            {reference.projects.length > 0 && (
                <div className="grid gap-2">
                    <Label>Projects &amp; objectives</Label>
                    <div className="flex flex-wrap gap-1.5">
                        {reference.projects.map((p) => (
                            <TogglePill
                                key={p.id}
                                label={p.title}
                                active={values.project_ids.includes(p.id)}
                                onClick={() =>
                                    onChange({
                                        project_ids: toggleNumber(
                                            values.project_ids,
                                            p.id,
                                        ),
                                    })
                                }
                            />
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

/** All three step sections stacked — used by the activity edit dialog. */
export function EvidenceFormFields(props: StepProps) {
    return (
        <div className="grid gap-6">
            <DetailsStepFields {...props} />
            <div className="border-t border-dashed border-stone-300 pt-5">
                <div className="mb-3 text-sm font-bold">Reflection</div>
                <ReflectionStepFields {...props} />
            </div>
            <div className="border-t border-dashed border-stone-300 pt-5">
                <CategorisationStepFields {...props} />
            </div>
        </div>
    );
}

function Textarea({
    id,
    value,
    rows,
    onChange,
}: {
    id: string;
    value: string;
    rows: number;
    onChange: (value: string) => void;
}) {
    return (
        <textarea
            id={id}
            value={value}
            rows={rows}
            onChange={(e) => onChange(e.target.value)}
            className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm leading-relaxed shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
        />
    );
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
