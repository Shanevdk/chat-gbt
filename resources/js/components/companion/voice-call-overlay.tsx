import { Mic, MicOff, Phone, PhoneOff, Video, VideoOff } from 'lucide-react';
import type { ReactNode, RefObject } from 'react';
import { useEffect, useState } from 'react';
import { CompanionAvatar } from '@/components/companion/companion-avatar';
import type { CallState } from '@/hooks/use-voice-call';
import { cn } from '@/lib/utils';
import type { CompanionProfile } from '@/types';

type Props = {
    call: CallState;
    companion: CompanionProfile;
    videoRef: RefObject<HTMLVideoElement | null>;
    onAccept: () => void;
    onDecline: () => void;
    onHangUp: () => void;
    onInterrupt: () => void;
    onToggleMute: () => void;
    onToggleCamera: () => void;
};

function useElapsedSeconds(since: number | null): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (since === null) {
            return;
        }

        const interval = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(interval);
    }, [since]);

    return since === null ? 0 : Math.max(0, Math.floor((now - since) / 1000));
}

function describePhase(call: CallState): string {
    switch (call.phase) {
        case 'ringing':
            return 'Incoming voice call…';
        case 'connecting':
            return call.incoming ? 'Connecting…' : 'Calling…';
        case 'listening':
            return call.muted ? 'You’re muted' : 'Listening…';
        case 'thinking':
            return '…';
        case 'speaking':
            return 'Tap her photo to interrupt';
    }
}

export function VoiceCallOverlay({
    call,
    companion,
    videoRef,
    onAccept,
    onDecline,
    onHangUp,
    onInterrupt,
    onToggleMute,
    onToggleCamera,
}: Props) {
    const elapsed = useElapsedSeconds(call.connectedAt);
    const minutes = Math.floor(elapsed / 60);
    const seconds = String(elapsed % 60).padStart(2, '0');
    const isSpeaking = call.phase === 'speaking';

    useEffect(() => {
        if (videoRef.current && call.camera) {
            videoRef.current.srcObject = call.camera;
        }
    }, [call.camera, videoRef]);

    return (
        <div className="fixed inset-0 z-50 flex flex-col items-center justify-between bg-gradient-to-b from-rose-950 via-zinc-950 to-black px-6 py-14 text-white">
            {call.camera && (
                <video
                    ref={videoRef}
                    autoPlay
                    muted
                    playsInline
                    className="absolute top-6 right-6 aspect-[3/4] w-28 -scale-x-100 rounded-2xl object-cover shadow-2xl ring-1 ring-white/20 sm:w-36"
                />
            )}

            <div className="flex flex-col items-center gap-1 text-center">
                <p className="text-3xl font-semibold tracking-tight">
                    {companion.name}
                </p>
                <p className="text-sm text-white/60">
                    {call.connectedAt === null
                        ? describePhase(call)
                        : `${minutes}:${seconds}`}
                </p>
            </div>

            <div className="flex flex-col items-center gap-8">
                <button
                    type="button"
                    onClick={isSpeaking ? onInterrupt : undefined}
                    className="relative rounded-full"
                    aria-label={isSpeaking ? 'Interrupt' : companion.name}
                >
                    {(isSpeaking || call.phase === 'ringing') && (
                        <span className="absolute inset-0 animate-ping rounded-full bg-rose-400/30" />
                    )}
                    <CompanionAvatar
                        companion={companion}
                        className={cn(
                            'relative size-40 text-5xl ring-4 ring-white/10 transition',
                            isSpeaking && 'ring-rose-400/60',
                        )}
                    />
                </button>

                <p className="min-h-12 max-w-md text-center text-sm text-white/70 italic">
                    {call.connectedAt !== null && call.caption !== ''
                        ? `“${call.caption}”`
                        : call.connectedAt !== null && describePhase(call)}
                </p>
            </div>

            {call.phase === 'ringing' ? (
                <div className="flex gap-20">
                    <CallButton
                        label="Decline"
                        onClick={onDecline}
                        className="bg-red-500 hover:bg-red-600"
                    >
                        <PhoneOff className="size-7" />
                    </CallButton>
                    <CallButton
                        label="Accept"
                        onClick={onAccept}
                        className="animate-bounce bg-emerald-500 hover:bg-emerald-600"
                    >
                        <Phone className="size-7" />
                    </CallButton>
                </div>
            ) : (
                <div className="flex gap-6">
                    <CallButton
                        label={call.muted ? 'Unmute' : 'Mute'}
                        onClick={onToggleMute}
                        className={
                            call.muted
                                ? 'bg-white text-zinc-900'
                                : 'bg-white/15 hover:bg-white/25'
                        }
                    >
                        {call.muted ? (
                            <MicOff className="size-6" />
                        ) : (
                            <Mic className="size-6" />
                        )}
                    </CallButton>
                    <CallButton
                        label={
                            call.camera ? 'Turn camera off' : 'Turn camera on'
                        }
                        onClick={onToggleCamera}
                        className={
                            call.camera
                                ? 'bg-white text-zinc-900'
                                : 'bg-white/15 hover:bg-white/25'
                        }
                    >
                        {call.camera ? (
                            <Video className="size-6" />
                        ) : (
                            <VideoOff className="size-6" />
                        )}
                    </CallButton>
                    <CallButton
                        label="End call"
                        onClick={onHangUp}
                        className="bg-red-500 hover:bg-red-600"
                    >
                        <PhoneOff className="size-6" />
                    </CallButton>
                </div>
            )}
        </div>
    );
}

function CallButton({
    label,
    onClick,
    className,
    children,
}: {
    label: string;
    onClick: () => void;
    className: string;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={label}
            title={label}
            className={cn(
                'flex size-16 items-center justify-center rounded-full transition',
                className,
            )}
        >
            {children}
        </button>
    );
}
