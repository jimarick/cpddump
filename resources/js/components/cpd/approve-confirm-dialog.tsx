import { AlertTriangle, Loader2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

export interface ConfirmFile {
    id: number;
    name: string;
    /** Source item title, shown as `from "…"` in the merge flow. */
    from?: string;
}

export interface SensitiveFlag {
    type: string;
    excerpt?: string | null;
}

/**
 * The single "Before this is saved" popup shown after Approve/Merge: the
 * possible-sensitive-info warning and the keep-or-delete files decision live
 * together here, so the forms themselves carry no warning banners. This
 * popup IS the PII ack UI — proceeding past a shown warning sends
 * pii_ack: true; the server gate still enforces it.
 */
export function ApproveConfirmDialog({
    files,
    flags,
    flagLocation,
    verb,
    processing = false,
    onConfirm,
    onRemoveInfo,
    onCancel,
}: {
    files: ConfirmFile[];
    flags: SensitiveFlag[];
    /** Where the flagged content lives; defaults to files-vs-text wording. */
    flagLocation?: string;
    verb: 'Approve' | 'Merge';
    processing?: boolean;
    onConfirm: (keepIds: number[], piiAck: boolean) => void;
    /** Text-only flag path: scrub the typed text, then submit. */
    onRemoveInfo?: () => void;
    onCancel: () => void;
}) {
    const [keepIds, setKeepIds] = useState<number[]>([]);
    const hasFlags = flags.length > 0;
    const hasFiles = files.length > 0;

    return (
        <Dialog open onOpenChange={(o) => !o && !processing && onCancel()}>
            <DialogContent className="w-[min(100vw-2rem,26rem)] sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="font-display text-xl font-extrabold">
                        Before this is saved
                    </DialogTitle>
                </DialogHeader>

                {hasFlags && (
                    <div className="rounded-[10px] border-2 border-brand bg-brand-pale px-3.5 py-2.5 text-[13px]">
                        <span className="flex items-start gap-2">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0 text-brand" />
                            <span>
                                <span className="font-bold">
                                    Possible personal info:
                                </span>{' '}
                                {flags
                                    .map(
                                        (flag) =>
                                            flag.type.replace(/_/g, ' ') +
                                            (flag.excerpt
                                                ? ` “${flag.excerpt}”`
                                                : ''),
                                    )
                                    .join(' · ')}{' '}
                                — in{' '}
                                {flagLocation ??
                                    (hasFiles
                                        ? 'the files below'
                                        : 'your text')}
                                .
                            </span>
                        </span>
                    </div>
                )}

                {hasFiles && (
                    <>
                        <p className="text-[12.5px] text-stone-500">
                            Unticked files are deleted — your written entry is
                            kept either way.{' '}
                            {hasFlags
                                ? "Keeping a file records that you've checked it contains nothing identifiable."
                                : "Only keep a file you're sure contains nothing identifiable."}
                        </p>
                        <div className="grid gap-1.5">
                            {files.map((file) => (
                                <label
                                    key={file.id}
                                    className="flex items-start gap-2 text-[13px] text-stone-700"
                                >
                                    <Checkbox
                                        checked={keepIds.includes(file.id)}
                                        onCheckedChange={(v) =>
                                            setKeepIds((ids) =>
                                                v === true
                                                    ? [...ids, file.id]
                                                    : ids.filter(
                                                          (id) =>
                                                              id !== file.id,
                                                      ),
                                            )
                                        }
                                        className="mt-0.5"
                                    />
                                    <span className="min-w-0 truncate">
                                        {file.name}
                                        {file.from && (
                                            <span className="text-stone-400">
                                                {' '}
                                                from “{file.from}”
                                            </span>
                                        )}
                                    </span>
                                </label>
                            ))}
                        </div>
                    </>
                )}

                <div className="flex flex-wrap items-center gap-2 pt-1">
                    {hasFiles ? (
                        <>
                            <Button
                                onClick={() => onConfirm([], hasFlags)}
                                disabled={processing}
                                className="border-2 border-ink font-bold shadow-[2px_2px_0_#1c1917]"
                            >
                                {processing && (
                                    <Loader2 className="size-4 animate-spin" />
                                )}{' '}
                                {verb} &amp; delete files
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() => onConfirm(keepIds, hasFlags)}
                                disabled={processing || keepIds.length === 0}
                                className="border-2 border-ink font-semibold"
                            >
                                Keep selected &amp; {verb.toLowerCase()}
                            </Button>
                        </>
                    ) : (
                        <>
                            {onRemoveInfo && (
                                <Button
                                    onClick={onRemoveInfo}
                                    disabled={processing}
                                    className="border-2 border-ink font-bold shadow-[2px_2px_0_#1c1917]"
                                >
                                    {processing && (
                                        <Loader2 className="size-4 animate-spin" />
                                    )}{' '}
                                    Remove personal info &amp; {verb.toLowerCase()}
                                </Button>
                            )}
                            <Button
                                variant="outline"
                                onClick={() => onConfirm([], true)}
                                disabled={processing}
                                className="border-2 border-ink font-semibold"
                            >
                                {verb} — I've checked it
                            </Button>
                        </>
                    )}
                    <Button
                        variant="ghost"
                        onClick={onCancel}
                        disabled={processing}
                        className="text-stone-500"
                    >
                        Back
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
