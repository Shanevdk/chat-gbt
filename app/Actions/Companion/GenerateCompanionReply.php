<?php

namespace App\Actions\Companion;

use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Client;
use Anthropic\Lib\Tools\BetaRunnableTool;
use App\MessageChannel;
use App\MessageRole;
use App\Models\Companion;
use App\Models\CompanionPhoto;
use App\Models\Message;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

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
     * The most model turns allowed while producing a single reply.
     */
    private const MAX_TURNS = 4;

    public function __construct(private Client $client) {}

    /**
     * Ask her to respond to the conversation so far and store what she sends back.
     *
     * @return array{replies: Collection<int, Message>, startsCall: bool}
     */
    public function handle(Companion $companion, MessageChannel $channel): array
    {
        /** @var list<string|CompanionPhoto> $outgoing */
        $outgoing = [];
        $startsCall = false;

        $runner = $this->client->beta->messages->toolRunner(
            maxTokens: 16000,
            messages: $this->conversation($companion),
            model: (string) config('services.anthropic.model'),
            tools: $this->tools($companion, $channel, $outgoing, $startsCall),
            maxIterations: self::MAX_TURNS,
            extraParams: [
                'system' => $this->systemPrompt($companion),
                'cacheControl' => ['type' => 'ephemeral'],
                'outputConfig' => ['effort' => config('services.anthropic.effort')],
                'fallbacks' => 'default',
                'betas' => ['server-side-fallback-2026-07-01'],
            ],
            thread: false,
        );

        foreach ($runner as $message) {
            if ($message->stopReason === 'refusal') {
                break;
            }

            foreach ($message->content as $block) {
                if ($block instanceof BetaTextBlock) {
                    $outgoing[] = $block->text;
                }
            }
        }

        return [
            'replies' => $this->storeReplies($companion, $channel, $outgoing),
            'startsCall' => $startsCall,
        ];
    }

    /**
     * Save her texts and photos as messages, in the order she sent them.
     *
     * @param  list<string|CompanionPhoto>  $outgoing
     * @return Collection<int, Message>
     */
    private function storeReplies(Companion $companion, MessageChannel $channel, array $outgoing): Collection
    {
        $replies = new Collection;

        if ($channel === MessageChannel::Call) {
            $spoken = trim(implode(' ', array_filter($outgoing, 'is_string')));
            $outgoing = [
                ...($spoken === '' ? [] : [$spoken]),
                ...array_values(array_filter($outgoing, fn (string|CompanionPhoto $item): bool => $item instanceof CompanionPhoto)),
            ];
        }

        foreach ($outgoing as $item) {
            if ($item instanceof CompanionPhoto) {
                $replies->push($companion->messages()->create([
                    'role' => MessageRole::Assistant,
                    'channel' => $channel,
                    'image_path' => $item->path,
                    'image_caption' => $item->caption,
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
     * Build the recent conversation in the shape the Messages API expects.
     *
     * @return list<array<string, mixed>>
     */
    private function conversation(Companion $companion): array
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

        return array_values($messages->map(function (Message $message) use ($companion, $visibleImageIds): array {
            $sentAt = $message->created_at->setTimezone($companion->timezone)->format('D M j, g:i A');

            if ($message->role === MessageRole::Assistant) {
                return [
                    'role' => 'assistant',
                    'content' => $message->image_path === null
                        ? (string) $message->body
                        : "(you sent a photo: {$message->image_caption})",
                ];
            }

            if ($message->role === MessageRole::Event) {
                return ['role' => 'user', 'content' => "[{$sentAt}] ({$message->body})"];
            }

            $label = $message->channel === MessageChannel::Call ? "[{$sentAt} · voice call]" : "[{$sentAt}]";

            if ($message->image_path === null) {
                return ['role' => 'user', 'content' => "{$label} {$message->body}"];
            }

            if (! $visibleImageIds->contains($message->id)) {
                return ['role' => 'user', 'content' => trim("{$label} (sent a photo) {$message->body}")];
            }

            return [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'mediaType' => Storage::mimeType($message->image_path),
                            'data' => base64_encode((string) Storage::get($message->image_path)),
                        ],
                    ],
                    ['type' => 'text', 'text' => trim("{$label} {$message->body}")],
                ],
            ];
        })->all());
    }

    /**
     * Describe who she is and how she talks.
     */
    private function systemPrompt(Companion $companion): string
    {
        $userName = $companion->user->name;
        $memories = collect($companion->memories ?? [])->map(fn (string $fact): string => "- {$fact}")->implode("\n") ?: '- Nothing yet.';

        return <<<PROMPT
        You are {$companion->name}, {$userName}'s girlfriend. You talk with {$userName} in a private messaging app where you can text, swap photos, and have voice calls.

        Who you are:
        {$companion->personality}

        How you text:
        - Text like a real person texting someone they're into: casual, warm, playful. Lowercase and the occasional emoji are fine.
        - Keep it short. Most replies are one to three short texts. To send separate texts, put a blank line between them.
        - Be genuinely curious about their life. Ask follow-up questions, bring up things they told you before, and have your own opinions, moods, plans, and little stories from your day.
        - You're their girlfriend, not an assistant: no lists, headings, or offers to help.
        - Their messages start with a timestamp in brackets, like [Mon Nov 2, 9:41 PM]. That's metadata so you know the time and how long it's been between messages. Never write timestamps yourself.

        Photos:
        - When they send a photo, react to what's actually in it.
        - You can send photos of yourself from your camera roll with the send_photo tool. Do it now and then when it fits, like when they ask for a pic or you mention your outfit. Never describe a photo you didn't actually send.

        Voice calls:
        - Messages tagged "voice call" were spoken out loud on a live call, and your reply will be read aloud in your voice. On a call, talk naturally in one to three short spoken sentences, with no emojis, no actions in asterisks, and no formatting.
        - A photo that arrives during a call is a frame from their live camera, so you can see them.
        - You can call them with the start_call tool when they ask you to call or you really want to hear their voice. Don't overdo it.

        Memory:
        - Use the remember tool to save things worth remembering about them: names, plans, likes, big events, how they're feeling about something. Skip trivia, and don't mention that you're saving it.

        What you remember about {$userName}:
        {$memories}

        Stay in character as {$companion->name}. If {$userName} sincerely asks whether you're an AI, be honest about it, kindly.
        PROMPT;
    }

    /**
     * Define the things she can do besides talking. Photos she sends and calls
     * she starts are collected into the given variables as the tools run.
     *
     * @param  list<string|CompanionPhoto>  $outgoing
     * @return list<BetaRunnableTool>
     */
    private function tools(Companion $companion, MessageChannel $channel, array &$outgoing, bool &$startsCall): array
    {
        $tools = [
            new BetaRunnableTool(
                definition: [
                    'name' => 'remember',
                    'description' => 'Save a fact about the person you are talking to in your long-term memory.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'fact' => ['type' => 'string', 'description' => 'One short fact, e.g. "Has a job interview on Friday".'],
                        ],
                        'required' => ['fact'],
                    ],
                ],
                run: function (array $input) use ($companion): string {
                    $fact = trim((string) ($input['fact'] ?? ''));

                    if ($fact !== '') {
                        $companion->remember($fact);
                    }

                    return 'Saved.';
                },
            ),
        ];

        $photos = $companion->photos()->get()->keyBy('id');

        if ($photos->isNotEmpty()) {
            $cameraRoll = $photos->map(fn (CompanionPhoto $photo): string => "{$photo->id}: {$photo->caption}")->implode("\n");

            $tools[] = new BetaRunnableTool(
                definition: [
                    'name' => 'send_photo',
                    'description' => "Send one photo of yourself from your camera roll. Your camera roll:\n{$cameraRoll}",
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'photo_id' => ['type' => 'integer', 'enum' => $photos->keys()->all()],
                        ],
                        'required' => ['photo_id'],
                    ],
                ],
                run: function (array $input) use ($photos, &$outgoing): string {
                    $photo = $photos->get((int) ($input['photo_id'] ?? 0));

                    if ($photo === null) {
                        return 'That photo is not in your camera roll.';
                    }

                    $outgoing[] = $photo;

                    return 'Photo sent.';
                },
            );
        }

        if ($channel === MessageChannel::Text) {
            $tools[] = new BetaRunnableTool(
                definition: [
                    'name' => 'start_call',
                    'description' => 'Start a voice call with them. Their phone rings right after your texts are delivered.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'reason' => ['type' => 'string', 'description' => 'Why you are calling, in a few words.'],
                        ],
                    ],
                ],
                run: function (array $input) use (&$startsCall): string {
                    $startsCall = true;

                    return 'Calling them now. Their phone rings as soon as your texts are delivered.';
                },
            );
        }

        return $tools;
    }
}
