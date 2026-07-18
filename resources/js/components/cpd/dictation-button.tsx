import { Loader2, Mic, Square } from 'lucide-react';
import { useDictation } from '@/hooks/use-dictation';
import { cn } from '@/lib/utils';

/**
 * The round mic button: click, talk, click, transcript arrives. While
 * recording, a bubble under the button shows the browser's rough live
 * preview (where supported); the accurate AI transcript replaces it.
 */
export function DictationButton({
    onTranscript,
    className,
}: {
    onTranscript: (text: string) => void;
    className?: string;
}) {
    const dictation = useDictation(onTranscript);

    const preview =
        dictation.preview.length > 160
            ? `…${dictation.preview.slice(-160)}`
            : dictation.preview;

    return (
        <span className={cn('relative inline-block', className)}>
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

            {(dictation.recording || dictation.transcribing) &&
                preview !== '' && (
                    <span className="absolute top-full right-0 z-30 mt-1.5 block w-64 max-w-[75vw] rounded-[10px] border-[1.5px] border-ink bg-white px-3 py-2 text-left shadow-[3px_3px_0_rgba(28,25,23,.15)]">
                        <span className="block text-[10px] font-bold tracking-[0.08em] text-stone-400 uppercase">
                            {dictation.transcribing
                                ? 'Tidying up…'
                                : 'Hearing…'}
                        </span>
                        <span
                            className={cn(
                                'mt-0.5 block text-[12px] leading-snug text-stone-600',
                                dictation.transcribing && 'opacity-60',
                            )}
                        >
                            {preview}
                        </span>
                    </span>
                )}
        </span>
    );
}
