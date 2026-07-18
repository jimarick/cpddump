import type { ComponentProps } from 'react';
import { DictationButton } from '@/components/cpd/dictation-button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/** Appends a transcript to whatever is already typed. */
const append = (current: string, text: string) =>
    current.trim() ? `${current.trim()} ${text}` : text;

interface DictatedInputProps extends Omit<
    ComponentProps<typeof Input>,
    'value' | 'onChange'
> {
    value: string;
    onValueChange: (value: string) => void;
}

/** A single-line text input with the dictation mic inside its right edge. */
export function DictatedInput({
    value,
    onValueChange,
    className,
    ...props
}: DictatedInputProps) {
    return (
        <div className="relative">
            <Input
                value={value}
                onChange={(e) => onValueChange(e.target.value)}
                className={cn('pr-10', className)}
                {...props}
            />
            <DictationButton
                onTranscript={(text) => onValueChange(append(value, text))}
                className="absolute top-1/2 right-1.5 -translate-y-1/2"
            />
        </div>
    );
}

interface DictatedTextareaProps extends Omit<
    ComponentProps<'textarea'>,
    'value' | 'onChange'
> {
    value: string;
    onValueChange: (value: string) => void;
}

/** A textarea with the dictation mic in its top-right corner. */
export function DictatedTextarea({
    value,
    onValueChange,
    className,
    rows = 3,
    ...props
}: DictatedTextareaProps) {
    return (
        <div className="relative">
            <textarea
                value={value}
                rows={rows}
                onChange={(e) => onValueChange(e.target.value)}
                className={cn(
                    'w-full rounded-md border border-input bg-transparent px-3 py-2 pr-10 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                    className,
                )}
                {...props}
            />
            <DictationButton
                onTranscript={(text) => onValueChange(append(value, text))}
                className="absolute top-2 right-2"
            />
        </div>
    );
}
