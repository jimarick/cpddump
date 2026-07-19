import { Paperclip, FileX2 } from 'lucide-react';
import type { AttachmentRef } from '@/types/cpd';

/**
 * Clickable evidence-file chips — each opens in a new tab (PDFs and images
 * render inline). Purged attachments (file deliberately not kept) show as
 * honest, non-clickable stubs.
 */
export function AttachmentLinks({
    attachments,
}: {
    attachments: AttachmentRef[];
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
                    <a
                        key={attachment.id}
                        href={`/attachments/${attachment.id}`}
                        target="_blank"
                        rel="noopener"
                        className="flex max-w-full items-center gap-1.5 rounded-full border-[1.5px] border-ink/20 bg-white px-2.5 py-1 text-[12px] font-semibold text-stone-600 transition-colors hover:border-ink hover:text-ink"
                    >
                        <Paperclip className="size-3 shrink-0 text-stone-400" />
                        <span className="truncate">{attachment.name}</span>
                    </a>
                ),
            )}
        </div>
    );
}
