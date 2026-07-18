import { Paperclip } from 'lucide-react';
import type { AttachmentRef } from '@/types/cpd';

/** Clickable evidence-file chips — each opens in a new tab (PDFs and images render inline). */
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
            {attachments.map((attachment) => (
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
            ))}
        </div>
    );
}
