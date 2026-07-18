import { useRef, useState } from 'react';
import { toast } from 'sonner';

/**
 * The browser's built-in speech recognition, used only for the live
 * preview while recording. Not in TypeScript's DOM lib yet (still
 * vendor-prefixed in every browser that ships it).
 */
interface SpeechRecognitionLike {
    continuous: boolean;
    interimResults: boolean;
    lang: string;
    onresult:
        | ((event: {
              results: ArrayLike<ArrayLike<{ transcript: string }>>;
          }) => void)
        | null;
    onerror: (() => void) | null;
    start: () => void;
    stop: () => void;
}

declare global {
    interface Window {
        SpeechRecognition?: new () => SpeechRecognitionLike;
        webkitSpeechRecognition?: new () => SpeechRecognitionLike;
    }
}

/**
 * Mic-button dictation: records with MediaRecorder and sends the clip to
 * /ai/transcribe for the real transcript. While recording, the browser's
 * own speech recognition (where available) provides a rough live preview —
 * it never becomes the final text.
 */
export function useDictation(onTranscript: (text: string) => void) {
    const [recording, setRecording] = useState(false);
    const [transcribing, setTranscribing] = useState(false);
    const [preview, setPreview] = useState('');
    const recorder = useRef<MediaRecorder | null>(null);
    const recognition = useRef<SpeechRecognitionLike | null>(null);
    const chunks = useRef<Blob[]>([]);

    const startPreview = () => {
        const Recognition =
            window.SpeechRecognition ?? window.webkitSpeechRecognition;

        if (!Recognition) {
            return;
        }

        try {
            const instance = new Recognition();
            instance.continuous = true;
            instance.interimResults = true;
            instance.lang = 'en-GB';
            instance.onresult = (event) => {
                let text = '';

                for (let i = 0; i < event.results.length; i++) {
                    text += event.results[i][0]?.transcript ?? '';
                }

                setPreview(text.trim());
            };
            instance.onerror = () => {
                // Preview is best-effort; the real transcript still happens.
            };
            instance.start();
            recognition.current = instance;
        } catch {
            // No preview — recording carries on regardless.
        }
    };

    const stop = () => {
        recorder.current?.stop();
        recognition.current?.stop();
        recognition.current = null;
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
                    setPreview('');
                }
            };

            mediaRecorder.start();
            recorder.current = mediaRecorder;
            setRecording(true);
            setPreview('');
            startPreview();
        } catch {
            toast.error(
                'Microphone access was blocked — allow it in your browser settings.',
            );
        }
    };

    return {
        recording,
        transcribing,
        preview,
        toggle: () => (recording ? stop() : start()),
    };
}
