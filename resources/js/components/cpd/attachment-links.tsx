import { Paperclip, FileX2, X } from 'lucide-react';
import type { AttachmentRef } from '@/types/cpd';

/**
 * Clickable evidence-file chips — each opens in a new tab (PDFs and images
 * render inline). Purged attachments (file deliberately not kept) show as
 * honest, non-clickable stubs. Pass onDelete to add an × on kept files.
 */
export function AttachmentLinks({
    attachments,
    onDelete,
}: {
    attachments: AttachmentRef[];
    onDelete?: (attachment: AttachmentRef) => void;
}) {
    if (attachments.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-wrap gap-1.5">
            {attachments.map((attachment) =>
                attachment.purged ? (
                    <span
                        key={attachment.id}
                        title="This file was not kept — only the drafted entry was stored."
                        className="flex max-w-full items-center gap-1.5 rounded-full border-[1.5px] border-dashed border-ink/15 bg-stone-50 px-2.5 py-1 text-[12px] font-semibold text-stone-400"
                    >
                        <FileX2 className="size-3 shrink-0" />
                        <span className="truncate line-through decoration-stone-300">
                            {attachment.name}
                        </span>
                        <span className="shrink-0 font-normal">not kept</span>
                    </span>
                ) : (
                    <span
                        key={attachment.id}
                        className="flex max-w-full items-center rounded-full border-[1.5px] border-ink/20 bg-white text-[12px] font-semibold text-stone-600 transition-colors hover:border-ink"
                    >
                        <a
                            href={`/attachments/${attachment.id}`}
                            target="_blank"
                            rel="noopener"
                            title={
                                attachment.from
                                    ? `From “${attachment.from}”`
                                    : undefined
                            }
                            className={`flex min-w-0 items-center gap-1.5 py-1 pl-2.5 hover:text-ink ${onDelete ? 'pr-1' : 'pr-2.5'}`}
                        >
                            <Paperclip className="size-3 shrink-0 text-stone-400" />
                            <span className="truncate">{attachment.name}</span>
                        </a>
                        {onDelete && (
                            <button
                                type="button"
                                title="Delete this file"
                                aria-label={`Delete ${attachment.name}`}
                                onClick={() => onDelete(attachment)}
                                className="cursor-pointer py-1 pr-2 pl-1 text-stone-400 hover:text-red-600"
                            >
                                <X className="size-3" />
                            </button>
                        )}
                    </span>
                ),
            )}
        </div>
    );
}
