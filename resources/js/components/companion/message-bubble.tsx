import { AudioLines, Phone } from 'lucide-react';
import { CompanionAvatar } from '@/components/companion/companion-avatar';
import {
    Dialog,
    DialogContent,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import type { ChatMessage, CompanionProfile } from '@/types';

function formatTime(timestamp: string): string {
    return new Date(timestamp).toLocaleTimeString([], {
        hour: 'numeric',
        minute: '2-digit',
    });
}

export function MessageBubble({
    message,
    companion,
    showAvatar,
}: {
    message: ChatMessage;
    companion: CompanionProfile;
    showAvatar: boolean;
}) {
    if (message.role === 'event') {
        return (
            <div className="flex justify-center py-1">
                <span className="inline-flex items-center gap-1.5 rounded-full bg-muted px-3 py-1 text-xs text-muted-foreground">
                    <Phone className="size-3" />
                    {message.body} · {formatTime(message.created_at)}
                </span>
            </div>
        );
    }

    const isMine = message.role === 'user';

    return (
        <div
            className={cn(
                'flex items-end gap-2',
                isMine ? 'justify-end' : 'justify-start',
            )}
        >
            {!isMine && (
                <div className="w-8 shrink-0">
                    {showAvatar && (
                        <CompanionAvatar
                            companion={companion}
                            className="size-8"
                        />
                    )}
                </div>
            )}

            <div
                className={cn(
                    'flex max-w-[78%] flex-col gap-1',
                    isMine ? 'items-end' : 'items-start',
                    message.status === 'sending' && 'opacity-60',
                )}
                title={formatTime(message.created_at)}
            >
                {message.image_url && (
                    <Dialog>
                        <DialogTrigger asChild>
                            <button
                                type="button"
                                className="overflow-hidden rounded-2xl"
                            >
                                <img
                                    src={message.image_url}
                                    alt={message.image_caption ?? 'Photo'}
                                    className="max-h-80 w-60 object-cover transition hover:brightness-95"
                                />
                            </button>
                        </DialogTrigger>
                        <DialogContent className="max-w-3xl border-0 bg-transparent p-0 shadow-none">
                            <DialogTitle className="sr-only">
                                {message.image_caption ?? 'Photo'}
                            </DialogTitle>
                            <img
                                src={message.image_url}
                                alt={message.image_caption ?? 'Photo'}
                                className="max-h-[85vh] w-full rounded-2xl object-contain"
                            />
                        </DialogContent>
                    </Dialog>
                )}

                {message.body && (
                    <div
                        className={cn(
                            'rounded-2xl px-3.5 py-2 text-[15px] leading-snug break-words whitespace-pre-wrap',
                            isMine
                                ? 'rounded-br-md bg-rose-500 text-white'
                                : 'rounded-bl-md bg-muted text-foreground',
                        )}
                    >
                        {message.channel === 'call' && (
                            <AudioLines className="mr-1.5 inline size-3.5 align-[-2px] opacity-70" />
                        )}
                        {message.body}
                    </div>
                )}

                {message.status === 'failed' && (
                    <span className="text-xs text-destructive">Not sent</span>
                )}
            </div>
        </div>
    );
}

export function TypingBubble({ companion }: { companion: CompanionProfile }) {
    return (
        <div className="flex items-end gap-2">
            <CompanionAvatar companion={companion} className="size-8" />
            <div className="flex gap-1 rounded-2xl rounded-bl-md bg-muted px-4 py-3">
                {[0, 150, 300].map((delay) => (
                    <span
                        key={delay}
                        className="size-2 animate-bounce rounded-full bg-muted-foreground/60"
                        style={{ animationDelay: `${delay}ms` }}
                    />
                ))}
            </div>
        </div>
    );
}
