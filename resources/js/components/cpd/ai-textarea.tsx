import { Loader2, Mic, Square, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Sparkle } from '@/components/brand/sparkle';
import { useDictation } from '@/hooks/use-dictation';
import { postJson } from '@/lib/api';
import { cn } from '@/lib/utils';

interface AiTextareaProps {
    id: string;
    value: string;
    onChange: (value: string) => void;
    rows?: number;
    placeholder?: string;
    /** What this text box is for — sent to the AI, e.g. "Reflection: what was learned" */
    field: string;
    /** Optional activity context (title, type, dates) to ground the AI */
    context?: string;
    className?: string;
}

/**
 * A textarea with the brand sparkle: the AI expands rough notes into a
 * polished paragraph (or drafts from context when empty). One-tap undo
 * restores the pre-sparkle text.
 */
export function AiTextarea({
    id,
    value,
    onChange,
    rows = 4,
    placeholder,
    field,
    context,
    className,
}: AiTextareaProps) {
    const [busy, setBusy] = useState(false);
    const [previous, setPrevious] = useState<string | null>(null);

    const dictation = useDictation((text) =>
        onChange(value.trim() ? `${value.trim()} ${text}` : text),
    );

    const sparkle = async () => {
        setBusy(true);

        try {
            const result = await postJson<{ text: string }>('/ai/text-assist', {
                field,
                text: value,
                context,
            });

            setPrevious(value);
            onChange(result.text);
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'The AI could not help just now.',
            );
        } finally {
            setBusy(false);
        }
    };

    const undo = () => {
        if (previous !== null) {
            onChange(previous);
            setPrevious(null);
        }
    };

    return (
        <div className={cn('relative', className)}>
            <textarea
                id={id}
                value={value}
                rows={rows}
                placeholder={placeholder}
                onChange={(e) => onChange(e.target.value)}
                className="w-full rounded-md border border-input bg-transparent px-3 py-2 pr-10 text-sm leading-relaxed shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
            />
            <div className="absolute top-2 right-2 flex flex-col gap-1">
                <button
                    type="button"
                    onClick={sparkle}
                    disabled={busy}
                    title={
                        value.trim()
                            ? 'Let AI polish this'
                            : 'Let AI draft this'
                    }
                    className="flex size-7 cursor-pointer items-center justify-center rounded-full border-[1.5px] border-brand/40 bg-white text-brand transition-colors hover:border-brand hover:bg-brand-tint disabled:opacity-60"
                >
                    {busy ? (
                        <Loader2 className="size-3.5 animate-spin" />
                    ) : (
                        <Sparkle size={13} />
                    )}
                </button>
                <button
                    type="button"
                    onClick={dictation.toggle}
                    disabled={dictation.transcribing}
                    title={
                        dictation.recording ? 'Stop and transcribe' : 'Dictate'
                    }
                    className={cn(
                        'flex size-7 cursor-pointer items-center justify-center rounded-full border-[1.5px] bg-white transition-colors disabled:opacity-60',
                        dictation.recording
                            ? 'animate-pulse border-red-500 text-red-500'
                            : 'border-stone-300 text-stone-500 hover:border-ink hover:text-ink',
                    )}
                >
                    {dictation.transcribing ? (
                        <Loader2 className="size-3.5 animate-spin" />
                    ) : dictation.recording ? (
                        <Square className="size-3" />
                    ) : (
                        <Mic className="size-3.5" />
                    )}
                </button>
                {previous !== null && !busy && (
                    <button
                        type="button"
                        onClick={undo}
                        title="Undo AI change"
                        className="flex size-7 cursor-pointer items-center justify-center rounded-full border-[1.5px] border-stone-300 bg-white text-stone-500 transition-colors hover:border-ink hover:text-ink"
                    >
                        <Undo2 className="size-3.5" />
                    </button>
                )}
            </div>
        </div>
    );
}
