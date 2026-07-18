import { Head, router } from '@inertiajs/react';
import { Loader2, Paperclip, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { CaveatNote } from '@/components/brand/caveat-note';
import { AttachmentLinks } from '@/components/cpd/attachment-links';
import { EvidenceFormFields } from '@/components/cpd/evidence-form-fields';
import type { EvidenceFormValues } from '@/components/cpd/evidence-form-fields';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { ActivityData, PeriodData, ReferenceData } from '@/types/cpd';

interface Props {
    activities: ActivityData[];
    period: PeriodData | null;
    reference: ReferenceData;
}

export default function ActivitiesIndex({
    activities,
    period,
    reference,
}: Props) {
    const [editing, setEditing] = useState<ActivityData | null>(null);

    const totalPoints = activities.reduce((sum, a) => sum + a.cpd_points, 0);

    return (
        <>
            <Head title="Activities" />

            <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="font-display text-[32px] leading-none font-semibold tracking-[-0.01em]">
                        Activities
                    </h1>
                    <p className="mt-1 text-[12.5px] font-semibold text-stone-500">
                        {period ? `Appraisal year: ${period.label} · ` : ''}
                        {activities.length} approved · {totalPoints} CPD points
                    </p>
                </div>
            </div>

            {activities.length === 0 ? (
                <div className="rounded-[14px] border-2 border-dashed border-stone-400 bg-white px-6 py-14 text-center">
                    <h2 className="font-display text-2xl font-semibold">
                        Nothing approved yet
                    </h2>
                    <p className="mx-auto mt-1 max-w-sm text-sm text-stone-500">
                        Approve things from your inbox and they'll pile up here
                        (usefully).
                    </p>
                    <CaveatNote rotate={-1.5} className="mt-3">
                        where approved things go
                    </CaveatNote>
                </div>
            ) : (
                <div className="overflow-hidden rounded-[14px] border-2 border-ink bg-white shadow-[6px_6px_0_rgba(28,25,23,.12)]">
                    {activities.map((activity, i) => (
                        <button
                            key={activity.id}
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
                                style={{ backgroundColor: activity.type.color }}
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
                            <span className="hidden text-xs whitespace-nowrap text-stone-500 sm:inline">
                                {activity.starts_on ?? '—'}
                            </span>
                            <span className="rounded-full bg-brand-tint px-2 py-0.5 text-[10.5px] font-semibold whitespace-nowrap text-brand-dark">
                                {activity.cpd_points} pts
                            </span>
                        </button>
                    ))}
                </div>
            )}

            {editing && (
                <EditActivityDialog
                    key={editing.id}
                    activity={editing}
                    reference={reference}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

function EditActivityDialog({
    activity,
    reference,
    onClose,
}: {
    activity: ActivityData;
    reference: ReferenceData;
    onClose: () => void;
}) {
    const [values, setValues] = useState<EvidenceFormValues>({
        title: activity.title,
        activity_type_slug: activity.type.slug,
        starts_on: activity.starts_on ?? '',
        ends_on: activity.ends_on ?? '',
        organisation: activity.organisation ?? '',
        cpd_points: activity.cpd_points,
        summary: activity.details ?? '',
        reflection: activity.reflection ?? {},
        category_slugs: activity.categories.map((c) => c.slug),
        domain_codes: activity.domains.map((d) => d.code),
        attribute_codes: activity.attribute_codes,
        project_ids: activity.projects.map((p) => p.id),
    });

    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

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
        if (
            !confirm(
                'Delete this activity? Its evidence attachments go with it.',
            )
        ) {
            return;
        }

        setProcessing(true);
        router.delete(`/activities/${activity.id}`, {
            onSuccess: onClose,
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[92vh] w-[min(100vw-2rem,52rem)] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle className="font-display text-2xl font-semibold">
                        Edit activity
                    </DialogTitle>
                </DialogHeader>

                <AttachmentLinks attachments={activity.attachments} />

                <EvidenceFormFields
                    values={values}
                    onChange={(patch) => setValues((v) => ({ ...v, ...patch }))}
                    reference={reference}
                    errors={errors}
                />

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
                        onClick={onClose}
                        disabled={processing}
                        className="border-2 border-ink"
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="ghost"
                        onClick={remove}
                        disabled={processing}
                        className="ml-auto text-red-600 hover:text-red-700"
                    >
                        <Trash2 className="size-4" /> Delete
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
