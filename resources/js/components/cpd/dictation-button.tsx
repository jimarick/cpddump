import { Loader2, Mic, Square } from 'lucide-react';
import { useDictation } from '@/hooks/use-dictation';
import { cn } from '@/lib/utils';

/** The round mic button: click, talk, click, transcript arrives. */
export function DictationButton({
    onTranscript,
    className,
}: {
    onTranscript: (text: string) => void;
    className?: string;
}) {
    const dictation = useDictation(onTranscript);

    return (
        <button
            type="button"
            onClick={dictation.toggle}
            disabled={dictation.transcribing}
            title={dictation.recording ? 'Stop and transcribe' : 'Dictate'}
            className={cn(
                'flex size-7 cursor-pointer items-center justify-center rounded-full border-[1.5px] bg-white transition-colors disabled:opacity-60',
                dictation.recording
                    ? 'animate-pulse border-red-500 text-red-500'
                    : 'border-stone-300 text-stone-500 hover:border-ink hover:text-ink',
                className,
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
    );
}
