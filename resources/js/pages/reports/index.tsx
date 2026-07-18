import { Head, router, useForm } from '@inertiajs/react';
import {
    Check,
    Copy,
    Download,
    FileText,
    FolderArchive,
    Loader2,
    Trash2,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useMemo, useState } from 'react';
import { CaveatNote } from '@/components/brand/caveat-note';
import { Sparkle } from '@/components/brand/sparkle';
import {
    DictatedInput,
    DictatedTextarea,
} from '@/components/cpd/dictated-fields';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

interface ReportData {
    id: number;
    kind: 'question' | 'report' | 'evidence_zip';
    question: string | null;
    status: 'pending' | 'ready' | 'failed';
    failure_reason: string | null;
    files: number | null;
    content: string | null;
    period: string | null;
    created_at: string;
}

interface Props {
    reports: ReportData[];
    period: { id: number; label: string } | null;
}

export default function ReportsIndex({ reports, period }: Props) {
    const [viewing, setViewing] = useState<ReportData | null>(null);

    const pending = useMemo(
        () => reports.some((r) => r.status === 'pending'),
        [reports],
    );

    useEffect(() => {
        if (!pending) {
            return;
        }

        const timer = setInterval(
            () => router.reload({ only: ['reports'] }),
            4000,
        );

        return () => clearInterval(timer);
    }, [pending]);

    return (
        <>
            <Head title="Reports" />

            <div className="mb-5">
                <h1 className="font-display text-[32px] leading-none font-semibold tracking-[-0.01em]">
                    Reports
                </h1>
                <p className="mt-1 text-[12.5px] font-semibold text-stone-500">
                    Ask your appraisal form's questions here — the AI answers
                    from everything you've dumped.
                </p>
            </div>

            <div className="grid gap-5 lg:grid-cols-[1fr_320px]">
                <AskCard />
                <FullReportCard period={period} />
            </div>

            <div className="mt-7">
                <h2 className="mb-3 font-display text-xl font-semibold">
                    Previous answers &amp; reports
                </h2>

                {reports.length === 0 ? (
                    <div className="rounded-[14px] border-2 border-dashed border-stone-400 bg-white px-6 py-10 text-center">
                        <p className="text-sm text-stone-500">
                            Nothing generated yet — ask your first question
                            above.
                        </p>
                        <CaveatNote rotate={-1} className="mt-2">
                            "what are your greatest achievements?" — try it
                        </CaveatNote>
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-[14px] border-2 border-ink bg-white shadow-[6px_6px_0_rgba(28,25,23,.12)]">
                        {reports.map((report, i) => (
                            <button
                                key={report.id}
                                type="button"
                                disabled={report.status === 'pending'}
                                onClick={() => {
                                    if (report.status !== 'ready') {
                                        return;
                                    }

                                    if (report.kind === 'evidence_zip') {
                                        window.location.href = `/reports/${report.id}/download`;
                                    } else {
                                        setViewing(report);
                                    }
                                }}
                                className={`flex w-full items-center gap-3 px-4 py-3 text-left md:px-5 ${
                                    i === reports.length - 1
                                        ? ''
                                        : 'border-b border-ink/7'
                                } ${report.status === 'pending' ? 'cursor-default opacity-70' : 'cursor-pointer hover:bg-[#fffbf8]'}`}
                            >
                                {report.kind === 'report' ? (
                                    <FileText className="size-4 shrink-0 text-brand" />
                                ) : report.kind === 'evidence_zip' ? (
                                    <FolderArchive className="size-4 shrink-0 text-brand" />
                                ) : (
                                    <Sparkle
                                        size={14}
                                        className="shrink-0 text-brand"
                                    />
                                )}
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[13.5px] font-semibold">
                                        {report.kind === 'report'
                                            ? `Full report — ${report.period}`
                                            : report.kind === 'evidence_zip'
                                              ? `Evidence bundle — ${report.period}${report.files ? ` (${report.files} files)` : ''}`
                                              : report.question}
                                    </span>
                                    {report.status === 'pending' && (
                                        <span className="flex items-center gap-1.5 text-xs text-stone-500">
                                            <Loader2 className="size-3 animate-spin" />{' '}
                                            writing…
                                        </span>
                                    )}
                                    {report.status === 'failed' && (
                                        <span className="text-xs text-red-600">
                                            {report.failure_reason ??
                                                'Failed — try again.'}
                                        </span>
                                    )}
                                </span>
                                <span className="hidden text-[11px] whitespace-nowrap text-stone-400 sm:inline">
                                    {new Date(
                                        report.created_at,
                                    ).toLocaleDateString('en-GB', {
                                        day: 'numeric',
                                        month: 'short',
                                    })}
                                </span>
                                {report.status === 'ready' && (
                                    <span className="flex items-center gap-1 rounded-[7px] border-[1.5px] border-ink bg-white px-2.5 py-1 text-[11.5px] font-bold whitespace-nowrap">
                                        {report.kind === 'evidence_zip' && (
                                            <Download className="size-3" />
                                        )}
                                        {report.kind === 'evidence_zip'
                                            ? 'Download'
                                            : 'View'}
                                    </span>
                                )}
                            </button>
                        ))}
                    </div>
                )}
            </div>

            {viewing && (
                <ReportDialog
                    key={viewing.id}
                    report={viewing}
                    onClose={() => setViewing(null)}
                />
            )}
        </>
    );
}

function AskCard() {
    const form = useForm({ kind: 'question', question: '', notes: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/reports', { onSuccess: () => form.reset() });
    };

    return (
        <form
            onSubmit={submit}
            className="rotate-[-0.3deg] rounded-[14px] border-2 border-ink bg-white p-5 shadow-[5px_5px_0_rgba(28,25,23,.12)]"
        >
            <div className="flex items-center gap-2">
                <Sparkle size={16} className="text-brand" />
                <h2 className="font-display text-xl font-semibold">
                    Ask your appraisal form's question
                </h2>
            </div>

            <div className="mt-4 grid gap-4">
                <div className="grid gap-1.5">
                    <Label htmlFor="ask-question">
                        The question, as your form asks it
                    </Label>
                    <DictatedInput
                        id="ask-question"
                        value={form.data.question}
                        onValueChange={(question) =>
                            form.setData('question', question)
                        }
                        placeholder="e.g. What personal and professional challenges have you faced this year?"
                    />
                    <InputError message={form.errors.question} />
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor="ask-notes">
                        Your rough thoughts (optional, but makes it yours)
                    </Label>
                    <DictatedTextarea
                        id="ask-notes"
                        value={form.data.notes}
                        rows={3}
                        onValueChange={(notes) => form.setData('notes', notes)}
                        placeholder="Bullet points, half-sentences, anything — the AI weaves your portfolio around them."
                    />
                </div>

                <Button
                    type="submit"
                    disabled={
                        form.processing || form.data.question.trim().length < 5
                    }
                    className="justify-self-start border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
                >
                    {form.processing ? (
                        <Loader2 className="size-4 animate-spin" />
                    ) : (
                        <Sparkle size={14} />
                    )}
                    Draft my answer
                </Button>
            </div>
        </form>
    );
}

function FullReportCard({
    period,
}: {
    period: { id: number; label: string } | null;
}) {
    const form = useForm({ kind: 'report' });

    return (
        <div className="rotate-[0.4deg] rounded-[14px] border-2 border-dashed border-stone-400 bg-white p-5">
            <div className="flex items-center gap-2">
                <FileText className="size-4 text-brand" />
                <h2 className="font-display text-xl font-semibold">
                    The whole year, written up
                </h2>
            </div>
            <p className="mt-2 text-[13px] leading-relaxed text-pretty text-stone-500">
                One structured document for{' '}
                {period?.label ?? 'your appraisal year'}: summary, evidence by
                category, best reflections, PDP progress and gaps — ready to
                paste into your appraisal software.
            </p>
            <Button
                variant="outline"
                disabled={form.processing || !period}
                onClick={() => form.post('/reports')}
                className="mt-4 border-2 border-ink font-semibold"
            >
                {form.processing && <Loader2 className="size-4 animate-spin" />}
                Write my report
            </Button>
            <div className="mt-4 border-t border-dashed border-stone-300 pt-3">
                <p className="text-[12px] text-stone-500">
                    Your appraiser wants the actual certificates too:
                </p>
                <button
                    type="button"
                    disabled={!period}
                    onClick={() => router.post('/reports/evidence-export')}
                    className="mt-1 flex cursor-pointer items-center gap-1.5 text-[12.5px] font-semibold text-brand hover:text-brand-dark disabled:opacity-50"
                >
                    <FolderArchive className="size-3.5" /> Download all evidence
                    for {period?.label ?? 'this year'} (zip)
                </button>
            </div>
        </div>
    );
}

function ReportDialog({
    report,
    onClose,
}: {
    report: ReportData;
    onClose: () => void;
}) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        await navigator.clipboard.writeText(report.content ?? '');
        setCopied(true);
        setTimeout(() => setCopied(false), 1600);
    };

    const download = () => {
        const blob = new Blob([report.content ?? ''], {
            type: 'text/markdown',
        });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download =
            report.kind === 'report'
                ? `cpd-report-${report.period ?? 'period'}.md`
                : 'appraisal-answer.md';
        a.click();
        URL.revokeObjectURL(url);
    };

    const remove = () => {
        if (!confirm('Delete this?')) {
            return;
        }

        router.delete(`/reports/${report.id}`, { onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[92vh] w-[min(100vw-2rem,52rem)] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle className="pr-8 font-display text-2xl leading-tight font-semibold">
                        {report.kind === 'report'
                            ? `Full report — ${report.period}`
                            : report.question}
                    </DialogTitle>
                </DialogHeader>

                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        onClick={copy}
                        className="border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
                    >
                        {copied ? (
                            <Check className="size-4" />
                        ) : (
                            <Copy className="size-4" />
                        )}
                        {copied ? 'Copied' : 'Copy'}
                    </Button>
                    <Button
                        variant="outline"
                        onClick={download}
                        className="border-2 border-ink font-semibold"
                    >
                        <Download className="size-4" /> Download .md
                    </Button>
                    <Button
                        variant="ghost"
                        onClick={remove}
                        className="ml-auto text-red-600 hover:text-red-700"
                    >
                        <Trash2 className="size-4" />
                    </Button>
                </div>

                <div className="rounded-[10px] border border-dashed border-stone-300 bg-paper px-4 py-3">
                    <pre className="font-sans text-[13.5px] leading-relaxed whitespace-pre-wrap">
                        {report.content}
                    </pre>
                </div>

                <p className="text-[11.5px] text-stone-400">
                    AI-drafted from your portfolio — read it before it goes
                    anywhere official.
                </p>
            </DialogContent>
        </Dialog>
    );
}
