<?php

namespace App\Actions\Companion;

use App\Ai\Agents\CompanionAgent;
use App\Ai\CompanionOutbox;
use App\MessageChannel;
use App\MessageRole;
use App\Models\Companion;
use App\Models\Message;
use Illuminate\Support\Collection;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message as AiMessage;
use Laravel\Ai\Messages\UserMessage;

class GenerateCompanionReply
{
    /**
     * The minimum number of recent messages she reads on every turn.
     */
    private const HISTORY_SIZE = 40;

    /**
     * Older messages slide out of view in chunks of this size, so the
     * conversation prefix stays identical (and cacheable) between turns.
     */
    private const HISTORY_STEP = 20;

    /**
     * How many of the most recent user photos she can actually see.
     */
    private const VISIBLE_IMAGES = 3;

    /**
     * Ask her to respond to the conversation so far and store what she sends back.
     *
     * @return array{replies: Collection<int, Message>, startsCall: bool}
     */
    public function handle(Companion $companion, MessageChannel $channel): array
    {
        $turns = $this->turns($companion);
        $latest = array_pop($turns);
        $outbox = new CompanionOutbox;

        $response = (new CompanionAgent($companion, $channel, $this->toAiMessages($turns), $outbox))->prompt(
            $latest['text'],
            attachments: $latest['attachments'],
            provider: (string) config('services.companion.provider'),
            model: (string) config('services.companion.text_model'),
        );

        $assistantMessages = $response->messages->whereInstanceOf(AssistantMessage::class);

        $outgoing = [];

        if ($assistantMessages->isEmpty()) {
            $outgoing[] = $response->text;
        }

        foreach ($assistantMessages as $message) {
            $outgoing[] = (string) $message->content;

            foreach ($message->toolCalls as $toolCall) {
                if ($photo = $outbox->pullPhoto($toolCall->id) ?? $outbox->pullPhoto($toolCall->resultId)) {
                    $outgoing[] = $photo;
                }
            }
        }

        return [
            'replies' => $this->storeReplies($companion, $channel, [...$outgoing, ...$outbox->pullRemainingPhotos()]),
            'startsCall' => $outbox->startsCall,
        ];
    }

    /**
     * Save her texts and photos as messages, in the order she sent them.
     *
     * @param  list<string|array{path: string, caption: string}>  $outgoing
     * @return Collection<int, Message>
     */
    private function storeReplies(Companion $companion, MessageChannel $channel, array $outgoing): Collection
    {
        $replies = new Collection;

        if ($channel === MessageChannel::Call) {
            $spoken = trim(implode(' ', array_filter($outgoing, 'is_string')));
            $outgoing = [
                ...($spoken === '' ? [] : [$spoken]),
                ...array_values(array_filter($outgoing, 'is_array')),
            ];
        }

        foreach ($outgoing as $item) {
            if (is_array($item)) {
                $replies->push($companion->messages()->create([
                    'role' => MessageRole::Assistant,
                    'channel' => $channel,
                    'image_path' => $item['path'],
                    'image_caption' => $item['caption'],
                ]));

                continue;
            }

            $texts = $channel === MessageChannel::Call ? [$item] : (preg_split('/\n\s*\n/', $item) ?: [$item]);

            foreach ($texts as $text) {
                if (trim($text) === '') {
                    continue;
                }

                $replies->push($companion->messages()->create([
                    'role' => MessageRole::Assistant,
                    'channel' => $channel,
                    'body' => trim($text),
                ]));
            }
        }

        return $replies;
    }

    /**
     * Build the recent conversation as alternating turns, ending with the user.
     *
     * @return list<array{role: 'user'|'assistant', text: string, attachments: list<ImageFile>}>
     */
    private function turns(Companion $companion): array
    {
        $total = $companion->messages()->count();
        $skipped = intdiv(max(0, $total - self::HISTORY_SIZE), self::HISTORY_STEP) * self::HISTORY_STEP;

        $messages = $companion->messages()
            ->latest('id')
            ->take($total - $skipped)
            ->get()
            ->reverse()
            ->skipUntil(fn (Message $message): bool => $message->role !== MessageRole::Assistant)
            ->values();

        $visibleImageIds = $messages
            ->filter(fn (Message $message): bool => $message->role === MessageRole::User && $message->image_path !== null)
            ->take(-self::VISIBLE_IMAGES)
            ->pluck('id');

        $turns = [];

        foreach ($messages as $message) {
            $turn = $this->toTurn($message, $companion, $visibleImageIds->contains($message->id));
            $previous = array_key_last($turns);

            if ($previous !== null && $turns[$previous]['role'] === $turn['role']) {
                $turns[$previous]['text'] .= "\n".$turn['text'];
                $turns[$previous]['attachments'] = [...$turns[$previous]['attachments'], ...$turn['attachments']];
            } else {
                $turns[] = $turn;
            }
        }

        return $turns;
    }

    /**
     * Describe one stored message the way she reads it.
     *
     * @return array{role: 'user'|'assistant', text: string, attachments: list<ImageFile>}
     */
    private function toTurn(Message $message, Companion $companion, bool $showsImage): array
    {
        $sentAt = $message->created_at->setTimezone($companion->timezone)->format('D M j, g:i A');

        if ($message->role === MessageRole::Assistant) {
            return [
                'role' => 'assistant',
                'text' => $message->image_path === null
                    ? (string) $message->body
                    : "(you sent a photo: {$message->image_caption})",
                'attachments' => [],
            ];
        }

        if ($message->role === MessageRole::Event) {
            return ['role' => 'user', 'text' => "[{$sentAt}] ({$message->body})", 'attachments' => []];
        }

        $label = $message->channel === MessageChannel::Call ? "[{$sentAt} · voice call]" : "[{$sentAt}]";

        if ($message->image_path === null) {
            return ['role' => 'user', 'text' => "{$label} {$message->body}", 'attachments' => []];
        }

        return [
            'role' => 'user',
            'text' => trim($showsImage ? "{$label} {$message->body}" : "{$label} (sent a photo) {$message->body}"),
            'attachments' => $showsImage ? [ImageFile::fromStorage($message->image_path)] : [],
        ];
    }

    /**
     * @param  list<array{role: 'user'|'assistant', text: string, attachments: list<ImageFile>}>  $turns
     * @return list<AiMessage>
     */
    private function toAiMessages(array $turns): array
    {
        return array_map(
            fn (array $turn): AiMessage => $turn['role'] === 'user'
                ? new UserMessage($turn['text'], $turn['attachments'])
                : new AssistantMessage($turn['text']),
            $turns,
        );
    }
}
