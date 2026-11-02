import { Head, Link } from '@inertiajs/react';
import { ImagePlus, Phone, SendHorizontal, Settings2, X } from 'lucide-react';
import type { ClipboardEvent, FormEvent, KeyboardEvent } from 'react';
import { Fragment, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { CompanionAvatar } from '@/components/companion/companion-avatar';
import {
    MessageBubble,
    TypingBubble,
} from '@/components/companion/message-bubble';
import { VoiceCallOverlay } from '@/components/companion/voice-call-overlay';
import { Button } from '@/components/ui/button';
import { useVoiceCall } from '@/hooks/use-voice-call';
import {
    currentTimezone,
    downscaleImage,
    RequestError,
    sendRequest,
} from '@/lib/companion';
import { chat } from '@/routes';
import { edit as editCompanion } from '@/routes/companion';
import { store as storeMessage } from '@/routes/chat/messages';
import type {
    ChatMessage,
    CompanionProfile,
    SendMessageResponse,
} from '@/types';

type Attachment = {
    file: File;
    previewUrl: string;
};

function wait(milliseconds: number): Promise<void> {
    return new Promise((resolve) => window.setTimeout(resolve, milliseconds));
}

function formatDay(timestamp: string): string {
    const date = new Date(timestamp);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);

    if (date.toDateString() === today.toDateString()) {
        return 'Today';
    }

    if (date.toDateString() === yesterday.toDateString()) {
        return 'Yesterday';
    }

    return date.toLocaleDateString([], {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
    });
}

export default function Chat({
    companion,
    messages: initialMessages,
}: {
    companion: CompanionProfile;
    messages: ChatMessage[];
}) {
    const [messages, setMessages] = useState(initialMessages);
    const [draft, setDraft] = useState('');
    const [attachment, setAttachment] = useState<Attachment | null>(null);
    const [isTyping, setIsTyping] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const bottomRef = useRef<HTMLDivElement>(null);

    const appendMessages = (incoming: ChatMessage[]) =>
        setMessages((current) => [...current, ...incoming]);

    const voiceCall = useVoiceCall({
        voiceName: companion.voice_name,
        onMessages: appendMessages,
    });

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ block: 'end' });
    }, [messages.length, isTyping]);

    const attach = async (file: File | undefined) => {
        if (!file || !file.type.startsWith('image/')) {
            return;
        }

        const scaled = await downscaleImage(file);
        setAttachment({
            file: scaled,
            previewUrl: URL.createObjectURL(scaled),
        });
    };

    const revealReplies = async (replies: ChatMessage[]) => {
        for (const [index, reply] of replies.entries()) {
            if (index > 0) {
                await wait(
                    Math.min(2500, 400 + (reply.body?.length ?? 40) * 30),
                );
            }

            appendMessages([reply]);
        }
    };

    const send = async (event?: FormEvent) => {
        event?.preventDefault();

        const body = draft.trim();

        if ((body === '' && !attachment) || isTyping) {
            return;
        }

        const pendingId = `pending-${Date.now()}`;
        setMessages((current) => [
            ...current,
            {
                id: pendingId,
                role: 'user',
                channel: 'text',
                body: body || null,
                image_url: attachment?.previewUrl ?? null,
                image_caption: null,
                created_at: new Date().toISOString(),
                status: 'sending',
            },
        ]);
        setDraft('');
        setAttachment(null);
        setIsTyping(true);

        const form = new FormData();
        form.append('channel', 'text');
        form.append('timezone', currentTimezone());

        if (body !== '') {
            form.append('body', body);
        }

        if (attachment) {
            form.append('image', attachment.file);
        }

        try {
            const response = await sendRequest<SendMessageResponse>(
                storeMessage(),
                form,
            );
            setMessages((current) =>
                current.map((message) =>
                    message.id === pendingId ? response.sent : message,
                ),
            );
            await revealReplies(response.replies);

            if (response.incoming_call) {
                window.setTimeout(voiceCall.ring, 1500);
            }
        } catch (error) {
            const wasSaved =
                error instanceof RequestError && error.status === 503;

            setMessages((current) =>
                current.map((message) =>
                    message.id === pendingId
                        ? {
                              ...message,
                              status: wasSaved ? undefined : 'failed',
                          }
                        : message,
                ),
            );
            toast.error(
                error instanceof RequestError ? error.message : String(error),
            );
        } finally {
            setIsTyping(false);
        }
    };

    const handleKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            void send();
        }
    };

    const handlePaste = (event: ClipboardEvent<HTMLTextAreaElement>) => {
        const image = Array.from(event.clipboardData.files).find((file) =>
            file.type.startsWith('image/'),
        );

        if (image) {
            event.preventDefault();
            void attach(image);
        }
    };

    return (
        <>
            <Head title={companion.name} />

            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col">
                <header className="sticky top-0 z-10 flex items-center gap-3 border-b bg-background/85 px-4 py-3 backdrop-blur">
                    <div className="relative">
                        <CompanionAvatar
                            companion={companion}
                            className="size-10"
                        />
                        <span className="absolute right-0 bottom-0 size-3 rounded-full bg-emerald-500 ring-2 ring-background" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="truncate font-semibold">
                            {companion.name}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {isTyping ? 'typing…' : 'online'}
                        </p>
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        onClick={voiceCall.start}
                        disabled={voiceCall.call !== null}
                        aria-label={`Call ${companion.name}`}
                        title={`Call ${companion.name}`}
                    >
                        <Phone className="size-5" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        asChild
                        aria-label="Companion settings"
                        title="Companion settings"
                    >
                        <Link href={editCompanion()}>
                            <Settings2 className="size-5" />
                        </Link>
                    </Button>
                </header>

                <div className="flex flex-1 flex-col gap-1.5 px-4 py-4">
                    {messages.length === 0 && !isTyping && (
                        <div className="m-auto flex flex-col items-center gap-3 py-16 text-center">
                            <CompanionAvatar
                                companion={companion}
                                className="size-24 text-3xl"
                            />
                            <p className="text-lg font-semibold">
                                Say hi to {companion.name} 👋
                            </p>
                            <p className="max-w-xs text-sm text-muted-foreground">
                                Text her, send her a photo, or tap the phone to
                                call.
                            </p>
                        </div>
                    )}

                    {messages.map((message, index) => {
                        const previous = messages[index - 1];
                        const next = messages[index + 1];
                        const startsNewDay =
                            !previous ||
                            new Date(previous.created_at).toDateString() !==
                                new Date(message.created_at).toDateString();

                        return (
                            <Fragment key={message.id}>
                                {startsNewDay && (
                                    <p className="py-3 text-center text-xs font-medium text-muted-foreground">
                                        {formatDay(message.created_at)}
                                    </p>
                                )}
                                <MessageBubble
                                    message={message}
                                    companion={companion}
                                    showAvatar={
                                        next?.role !== 'assistant' &&
                                        !(isTyping && !next)
                                    }
                                />
                            </Fragment>
                        );
                    })}

                    {isTyping && <TypingBubble companion={companion} />}

                    <div ref={bottomRef} />
                </div>

                <form
                    onSubmit={(event) => void send(event)}
                    className="sticky bottom-0 border-t bg-background/85 px-3 py-3 backdrop-blur"
                >
                    {attachment && (
                        <div className="relative mb-2 ml-11 inline-block">
                            <img
                                src={attachment.previewUrl}
                                alt="Photo to send"
                                className="h-24 rounded-xl object-cover"
                            />
                            <button
                                type="button"
                                onClick={() => setAttachment(null)}
                                className="absolute -top-2 -right-2 rounded-full bg-foreground p-1 text-background"
                                aria-label="Remove photo"
                            >
                                <X className="size-3" />
                            </button>
                        </div>
                    )}

                    <div className="flex items-end gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            onClick={() => fileInputRef.current?.click()}
                            aria-label="Send a photo"
                            title="Send a photo"
                        >
                            <ImagePlus className="size-5" />
                        </Button>
                        <input
                            ref={fileInputRef}
                            type="file"
                            accept="image/*"
                            className="hidden"
                            onChange={(event) => {
                                void attach(event.target.files?.[0]);
                                event.target.value = '';
                            }}
                        />

                        <textarea
                            value={draft}
                            onChange={(event) => setDraft(event.target.value)}
                            onKeyDown={handleKeyDown}
                            onPaste={handlePaste}
                            rows={1}
                            placeholder={`Message ${companion.name}…`}
                            className="field-sizing-content max-h-36 min-h-9 flex-1 resize-none rounded-2xl border border-input bg-transparent px-4 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm"
                        />

                        <Button
                            type="submit"
                            size="icon"
                            disabled={
                                isTyping || (draft.trim() === '' && !attachment)
                            }
                            className="rounded-full bg-rose-500 text-white hover:bg-rose-600"
                            aria-label="Send"
                        >
                            <SendHorizontal className="size-4" />
                        </Button>
                    </div>
                </form>
            </div>

            {voiceCall.call && (
                <VoiceCallOverlay
                    call={voiceCall.call}
                    companion={companion}
                    videoRef={voiceCall.videoRef}
                    onAccept={voiceCall.accept}
                    onDecline={voiceCall.decline}
                    onHangUp={voiceCall.hangUp}
                    onInterrupt={voiceCall.interrupt}
                    onToggleMute={voiceCall.toggleMute}
                    onToggleCamera={voiceCall.toggleCamera}
                />
            )}
        </>
    );
}

Chat.layout = {
    breadcrumbs: [
        {
            title: 'Chat',
            href: chat(),
        },
    ],
};
