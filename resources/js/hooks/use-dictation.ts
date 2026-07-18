import { useRef, useState } from 'react';
import { toast } from 'sonner';

/**
 * Mic-button dictation: records with MediaRecorder, sends the clip to
 * /ai/transcribe, and hands back the transcript. Not live streaming —
 * click, talk, click, text appears.
 */
export function useDictation(onTranscript: (text: string) => void) {
    const [recording, setRecording] = useState(false);
    const [transcribing, setTranscribing] = useState(false);
    const recorder = useRef<MediaRecorder | null>(null);
    const chunks = useRef<Blob[]>([]);

    const stop = () => {
        recorder.current?.stop();
        setRecording(false);
    };

    const start = async () => {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: true,
            });
            const mimeType = MediaRecorder.isTypeSupported('audio/webm')
                ? 'audio/webm'
                : 'audio/mp4';
            const mediaRecorder = new MediaRecorder(stream, { mimeType });

            chunks.current = [];
            mediaRecorder.ondataavailable = (e) => chunks.current.push(e.data);
            mediaRecorder.onstop = async () => {
                stream.getTracks().forEach((track) => track.stop());
                setTranscribing(true);

                try {
                    const blob = new Blob(chunks.current, { type: mimeType });
                    const body = new FormData();
                    body.append(
                        'audio',
                        blob,
                        mimeType === 'audio/webm'
                            ? 'dictation.webm'
                            : 'dictation.m4a',
                    );

                    const match = document.cookie.match(
                        /(?:^|;\s*)XSRF-TOKEN=([^;]+)/,
                    );
                    const response = await fetch('/ai/transcribe', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-XSRF-TOKEN': match
                                ? decodeURIComponent(match[1])
                                : '',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body,
                    });

                    const data = (await response.json().catch(() => ({}))) as {
                        text?: string;
                        message?: string;
                    };

                    if (!response.ok || !data.text) {
                        throw new Error(
                            data.message ?? 'Could not transcribe that.',
                        );
                    }

                    onTranscript(data.text);
                } catch (error) {
                    toast.error(
                        error instanceof Error
                            ? error.message
                            : 'Could not transcribe that.',
                    );
                } finally {
                    setTranscribing(false);
                }
            };

            mediaRecorder.start();
            recorder.current = mediaRecorder;
            setRecording(true);
        } catch {
            toast.error(
                'Microphone access was blocked — allow it in your browser settings.',
            );
        }
    };

    return {
        recording,
        transcribing,
        toggle: () => (recording ? stop() : start()),
    };
}
