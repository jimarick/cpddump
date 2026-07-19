import { Head, router } from '@inertiajs/react';
import { Loader2, Paperclip, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
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
    PeriodData,
    ReferenceData,
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
                                    <span className="hidden text-xs whitespace-nowrap text-stone-500 sm:inline">
                                        {activity.starts_on ?? '—'}
                                    </span>
                                    <span className="rounded-full bg-brand-tint px-2 py-0.5 text-[10.5px] font-semibold whitespace-nowrap text-brand-dark">
                                        {activity.cpd_points} pts
                                    </span>
                                </button>
                            ))}
                        </div>
                    </div>
                </>
            )}

            {editing && (
                <EditActivityDialog
                    key={editing.id}
                    activity={editing}
                    reference={reference}
                    onClose={() => setEditing(null)}
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
                    <DialogTitle className="font-display text-2xl font-extrabold">
                        Edit activity
                    </DialogTitle>
                </DialogHeader>

                <AttachmentLinks attachments={activity.attachments} />

                {(activity.attachments.some((a) => !a.purged) ||
                    activity.reflection) && (
                    <button
                        type="button"
                        onClick={() => {
                            if (
                                confirm(
                                    'Remove patient information? Stored files are deleted and NHS numbers scrubbed from the text — your entry itself is kept.',
                                )
                            ) {
                                router.post(
                                    `/activities/${activity.id}/remove-pii`,
                                    {},
                                    { onSuccess: onClose },
                                );
                            }
                        }}
                        className="w-fit cursor-pointer text-[12px] font-semibold text-stone-400 underline decoration-dashed underline-offset-4 hover:text-ink"
                    >
                        Spotted patient info in this activity? Remove it
                    </button>
                )}

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
