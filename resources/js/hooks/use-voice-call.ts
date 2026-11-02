import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    captureVideoFrame,
    currentTimezone,
    RequestError,
    sendRequest,
} from '@/lib/companion';
import type { SpeechRecognitionLike } from '@/lib/voice';
import {
    createSpeechRecognition,
    loadVoices,
    pickVoice,
    speak,
    startRingtone,
    supportsVoiceCalls,
} from '@/lib/voice';
import { end as endCall, start as startCall } from '@/routes/chat/calls';
import { store as storeMessage } from '@/routes/chat/messages';
import type {
    ChatMessage,
    EndCallResponse,
    SendMessageResponse,
    StartCallResponse,
} from '@/types';

export type CallPhase =
    | 'ringing'
    | 'connecting'
    | 'listening'
    | 'thinking'
    | 'speaking';

export type CallState = {
    incoming: boolean;
    phase: CallPhase;
    muted: boolean;
    camera: MediaStream | null;
    caption: string;
    connectedAt: number | null;
};

type Options = {
    voiceName: string | null;
    onMessages: (messages: ChatMessage[]) => void;
};

export function useVoiceCall({ voiceName, onMessages }: Options) {
    const [call, setCall] = useState<CallState | null>(null);
    const callIdRef = useRef(0);
    const isActiveRef = useRef(false);
    const mutedRef = useRef(false);
    const interruptedRef = useRef(false);
    const connectedAtRef = useRef<number | null>(null);
    const recognitionRef = useRef<SpeechRecognitionLike | null>(null);
    const cameraRef = useRef<MediaStream | null>(null);
    const voiceRef = useRef<SpeechSynthesisVoice | null>(null);
    const videoRef = useRef<HTMLVideoElement | null>(null);

    useEffect(() => {
        if (call?.phase !== 'ringing') {
            return;
        }

        return startRingtone();
    }, [call?.phase]);

    useEffect(() => {
        return () => {
            isActiveRef.current = false;
            recognitionRef.current?.abort();
            cameraRef.current?.getTracks().forEach((track) => track.stop());
            window.speechSynthesis?.cancel();
        };
    }, []);

    const update = (changes: Partial<CallState>) =>
        setCall((current) => (current ? { ...current, ...changes } : current));

    const isCurrent = (callId: number) =>
        isActiveRef.current && callIdRef.current === callId;

    const stopListening = () => {
        const recognition = recognitionRef.current;
        recognitionRef.current = null;

        if (recognition) {
            recognition.onend = null;
            recognition.abort();
        }
    };

    const teardown = () => {
        isActiveRef.current = false;
        callIdRef.current++;
        connectedAtRef.current = null;
        stopListening();
        window.speechSynthesis?.cancel();
        cameraRef.current?.getTracks().forEach((track) => track.stop());
        cameraRef.current = null;
    };

    const say = async (callId: number, replies: ChatMessage[]) => {
        interruptedRef.current = false;
        update({ phase: 'speaking' });

        for (const reply of replies) {
            if (reply.body) {
                await speak(
                    reply.body,
                    voiceRef.current,
                    () => !isCurrent(callId) || interruptedRef.current,
                );
            }
        }
    };

    const listen = (callId: number) => {
        if (!isCurrent(callId)) {
            return;
        }

        update({ phase: 'listening', caption: '' });

        if (mutedRef.current) {
            return;
        }

        const recognition = createSpeechRecognition();

        if (!recognition) {
            return;
        }

        let transcript = '';
        let isBlocked = false;
        recognitionRef.current = recognition;

        recognition.onresult = (event) => {
            let interim = '';

            for (let i = event.resultIndex; i < event.results.length; i++) {
                const result = event.results[i];

                if (result.isFinal) {
                    transcript += result[0].transcript;
                } else {
                    interim += result[0].transcript;
                }
            }

            update({ caption: `${transcript} ${interim}`.trim() });
        };

        recognition.onerror = (event) => {
            if (['not-allowed', 'service-not-allowed'].includes(event.error)) {
                isBlocked = true;
                toast.error('Allow microphone access to talk to her.');
            } else if (event.error === 'network') {
                isBlocked = true;
                toast.error('Speech recognition lost its connection.');
            }
        };

        recognition.onend = () => {
            recognitionRef.current = null;

            if (!isCurrent(callId)) {
                return;
            }

            if (isBlocked) {
                void hangUp();
            } else if (transcript.trim() !== '') {
                void respond(callId, transcript.trim());
            } else {
                window.setTimeout(() => listen(callId), 100);
            }
        };

        try {
            recognition.start();
        } catch {
            recognitionRef.current = null;
        }
    };

    const respond = async (callId: number, transcript: string) => {
        update({ phase: 'thinking', caption: transcript });

        const form = new FormData();
        form.append('body', transcript);
        form.append('channel', 'call');
        form.append('timezone', currentTimezone());

        const frame =
            cameraRef.current && videoRef.current
                ? await captureVideoFrame(videoRef.current)
                : null;

        if (frame) {
            form.append('image', frame);
        }

        try {
            const response = await sendRequest<SendMessageResponse>(
                storeMessage(),
                form,
            );
            onMessages([response.sent, ...response.replies]);

            if (isCurrent(callId)) {
                await say(callId, response.replies);
            }
        } catch (error) {
            toast.error(
                error instanceof RequestError ? error.message : String(error),
            );
        }

        listen(callId);
    };

    const connect = async (incoming: boolean) => {
        if (!supportsVoiceCalls()) {
            toast.error('Voice calls need Chrome, Edge, or Safari.');
            setCall(null);

            return;
        }

        const callId = ++callIdRef.current;
        isActiveRef.current = true;
        mutedRef.current = false;
        setCall({
            incoming,
            phase: 'connecting',
            muted: false,
            camera: null,
            caption: '',
            connectedAt: null,
        });

        try {
            const microphone = await navigator.mediaDevices.getUserMedia({
                audio: true,
            });
            microphone.getTracks().forEach((track) => track.stop());
        } catch {
            toast.error('Allow microphone access to call her.');
            teardown();
            setCall(null);

            return;
        }

        voiceRef.current = pickVoice(await loadVoices(), voiceName);

        try {
            const response = await sendRequest<StartCallResponse>(startCall(), {
                incoming,
                timezone: currentTimezone(),
            });

            if (!isCurrent(callId)) {
                return;
            }

            connectedAtRef.current = Date.now();
            update({ connectedAt: connectedAtRef.current });
            onMessages([response.event, ...response.replies]);

            await say(callId, response.replies);
            listen(callId);
        } catch (error) {
            toast.error(
                error instanceof RequestError ? error.message : String(error),
            );
            teardown();
            setCall(null);
        }
    };

    const hangUp = async () => {
        const connectedAt = connectedAtRef.current;
        teardown();
        setCall(null);

        if (connectedAt === null) {
            return;
        }

        try {
            const response = await sendRequest<EndCallResponse>(endCall(), {
                duration: Math.round((Date.now() - connectedAt) / 1000),
            });
            onMessages([response.event]);
        } catch {
            // The call is over either way.
        }
    };

    const toggleMute = () => {
        const muted = !mutedRef.current;
        mutedRef.current = muted;
        update({ muted });

        if (muted) {
            stopListening();
            update({ caption: '' });
        } else if (call?.phase === 'listening') {
            listen(callIdRef.current);
        }
    };

    const toggleCamera = async () => {
        if (cameraRef.current) {
            cameraRef.current.getTracks().forEach((track) => track.stop());
            cameraRef.current = null;
            update({ camera: null });

            return;
        }

        try {
            const camera = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'user', width: { ideal: 1280 } },
            });
            cameraRef.current = camera;
            update({ camera });
        } catch {
            toast.error("Couldn't turn on your camera.");
        }
    };

    return {
        call,
        videoRef,
        start: () => void connect(false),
        accept: () => void connect(true),
        ring: () =>
            setCall({
                incoming: true,
                phase: 'ringing',
                muted: false,
                camera: null,
                caption: '',
                connectedAt: null,
            }),
        decline: () => setCall(null),
        hangUp: () => void hangUp(),
        interrupt: () => {
            interruptedRef.current = true;
            window.speechSynthesis.cancel();
        },
        toggleMute,
        toggleCamera: () => void toggleCamera(),
    };
}
