export type MessageRole = 'user' | 'assistant' | 'event';

export type MessageChannel = 'text' | 'call';

export type ChatMessage = {
    id: number | string;
    role: MessageRole;
    channel: MessageChannel;
    body: string | null;
    image_url: string | null;
    image_caption: string | null;
    created_at: string;
    status?: 'sending' | 'failed';
};

export type CompanionProfile = {
    name: string;
    avatar_url: string | null;
    voice_name: string | null;
};

export type SendMessageResponse = {
    sent: ChatMessage;
    replies: ChatMessage[];
    incoming_call: boolean;
};

export type StartCallResponse = {
    event: ChatMessage;
    replies: ChatMessage[];
};

export type EndCallResponse = {
    event: ChatMessage;
};
