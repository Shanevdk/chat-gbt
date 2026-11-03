<?php

use App\Ai\Agents\CompanionAgent;
use App\Ai\Tools\StartCall;
use App\MessageChannel;
use App\MessageRole;
use App\Models\Companion;
use App\Models\CompanionPhoto;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\ImagePrompt;
use Laravel\Ai\Responses\Data\ToolCall;

test('guests are redirected to the login page', function () {
    $this->get(route('chat'))->assertRedirect(route('login'));
});

test('the chat page introduces a default companion on the first visit', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('chat'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('chat')
            ->where('companion.name', 'Mia')
            ->has('messages', 0)
        );

    expect($user->companion)->not->toBeNull();
});

test('the chat page only shows the user\'s own messages', function () {
    $companion = Companion::factory()->create();
    Message::factory()->for($companion)->create(['body' => 'Mine']);
    Message::factory()->create(['body' => 'Someone else\'s']);

    $this->actingAs($companion->user)
        ->get(route('chat'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('messages', 1)
            ->where('messages.0.body', 'Mine')
        );
});

test('she replies to a text, splitting separate texts into separate messages', function () {
    $companion = Companion::factory()->create(['name' => 'Luna', 'personality' => 'Loves astronomy.']);
    CompanionAgent::fake(["heyyy you\n\nhow was the interview??"]);

    $this->actingAs($companion->user)->postJson(route('chat.messages.store'), [
        'body' => 'Hi Luna!',
        'channel' => 'text',
        'timezone' => 'Etc/GMT+5',
    ])
        ->assertOk()
        ->assertJsonPath('sent.body', 'Hi Luna!')
        ->assertJsonPath('replies.0.body', 'heyyy you')
        ->assertJsonPath('replies.1.body', 'how was the interview??')
        ->assertJsonPath('incoming_call', false);

    expect($companion->messages()->where('role', MessageRole::Assistant)->pluck('body')->all())
        ->toBe(['heyyy you', 'how was the interview??'])
        ->and($companion->fresh()->timezone)->toBe('Etc/GMT+5');

    CompanionAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Hi Luna!')
        && str_contains((string) $prompt->agent->instructions(), 'You are Luna')
        && str_contains((string) $prompt->agent->instructions(), 'Loves astronomy.'));
});

test('she can see a photo the user sends', function () {
    Storage::fake();
    $companion = Companion::factory()->create();
    CompanionAgent::fake(['omg is that your dog?']);

    $this->actingAs($companion->user)->post(route('chat.messages.store'), [
        'image' => UploadedFile::fake()->image('dog.jpg'),
        'channel' => 'text',
    ], ['Accept' => 'application/json'])->assertOk();

    Storage::assertExists($companion->messages()->where('role', MessageRole::User)->sole()->image_path);
    CompanionAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->attachments->count() === 1);
});

test('only the three most recent photos are sent as images', function () {
    Storage::fake();
    $companion = Companion::factory()->create();

    foreach (range(1, 4) as $number) {
        Storage::put("chat/photo-{$number}.jpg", UploadedFile::fake()->image("photo-{$number}.jpg")->getContent());
        Message::factory()->for($companion)->create(['body' => "Photo {$number}", 'image_path' => "chat/photo-{$number}.jpg"]);
    }

    CompanionAgent::fake(['cute']);

    $this->actingAs($companion->user)
        ->postJson(route('chat.messages.store'), ['body' => 'Thoughts?', 'channel' => 'text'])
        ->assertOk();

    CompanionAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->attachments->count() === 3
        && $prompt->contains('(sent a photo) Photo 1'));
});

test('she can take and send a new selfie', function () {
    Storage::fake();
    Image::fake();
    $companion = Companion::factory()->create(['appearance' => 'Short red hair and green eyes.']);
    CompanionAgent::fake([
        new ToolCall('call_1', 'send_selfie', ['description' => 'Beach selfie in a sun hat']),
        'miss the beach',
    ]);

    $this->actingAs($companion->user)
        ->postJson(route('chat.messages.store'), ['body' => 'Send me a pic', 'channel' => 'text'])
        ->assertOk()
        ->assertJsonCount(2, 'replies')
        ->assertJsonPath('replies.0.image_caption', 'Beach selfie in a sun hat')
        ->assertJsonPath('replies.1.body', 'miss the beach');

    $selfie = $companion->messages()->whereNotNull('image_path')->sole();

    Storage::assertExists($selfie->image_path);
    Image::assertGenerated(fn (ImagePrompt $prompt) => $prompt->contains('Beach selfie in a sun hat')
        && $prompt->contains('Short red hair and green eyes.')
        && $prompt->attachments->isEmpty());

    expect($companion->fresh()->avatar_path)->toBe($selfie->image_path);
});

test('her selfies use her profile picture so she looks the same', function () {
    Storage::fake();
    Image::fake();
    Storage::put('companions/avatars/face.jpg', UploadedFile::fake()->image('face.jpg')->getContent());
    $companion = Companion::factory()->create(['avatar_path' => 'companions/avatars/face.jpg']);
    CompanionAgent::fake([
        new ToolCall('call_1', 'send_selfie', ['description' => 'Coffee shop selfie']),
        'hi',
    ]);

    $this->actingAs($companion->user)
        ->postJson(route('chat.messages.store'), ['body' => 'Pic?', 'channel' => 'text'])
        ->assertOk();

    Image::assertGenerated(fn (ImagePrompt $prompt) => $prompt->attachments->count() === 1
        && $prompt->contains('exactly the same as in the reference photo'));

    expect($companion->fresh()->avatar_path)->toBe('companions/avatars/face.jpg');
});

test('a failed selfie does not break her reply', function () {
    Storage::fake();
    Image::fake(fn () => throw new AiException('Blocked by safety filters.'));
    $companion = Companion::factory()->create();
    CompanionAgent::fake([
        new ToolCall('call_1', 'send_selfie', ['description' => 'Gym selfie']),
        'ugh my camera is being weird',
    ]);

    $this->actingAs($companion->user)
        ->postJson(route('chat.messages.store'), ['body' => 'Pic?', 'channel' => 'text'])
        ->assertOk()
        ->assertJsonCount(1, 'replies')
        ->assertJsonPath('replies.0.body', 'ugh my camera is being weird');

    expect($companion->messages()->whereNotNull('image_path')->exists())->toBeFalse();
});

test('she can send a photo from her camera roll', function () {
    $companion = Companion::factory()->create();
    $photo = CompanionPhoto::factory()->for($companion)->create(['caption' => 'Beach selfie']);
    CompanionAgent::fake([
        new ToolCall('call_1', 'send_photo', ['photo_id' => $photo->id]),
        'miss the beach',
    ]);

    $this->actingAs($companion->user)
        ->postJson(route('chat.messages.store'), ['body' => 'Send me a pic', 'channel' => 'text'])
        ->assertOk()
        ->assertJsonPath('replies.0.image_caption', 'Beach selfie');

    expect($companion->messages()->whereNotNull('image_path')->sole()->image_path)->toBe($photo->path);
});

test('she cannot send a photo from another companion\'s camera roll', function () {
    $companion = Companion::factory()->create();
    CompanionPhoto::factory()->for($companion)->create();
    $otherPhoto = CompanionPhoto::factory()->create();
    CompanionAgent::fake([
        new ToolCall('call_1', 'send_photo', ['photo_id' => $otherPhoto->id]),
        'hmm my camera roll is acting up',
    ]);

    $this->actingAs($companion->user)
        ->postJson(route('chat.messages.store'), ['body' => 'Send me a pic', 'channel' => 'text'])
        ->assertOk()
        ->assertJsonCount(1, 'replies');

    expect($companion->messages()->whereNotNull('image_path')->exists())->toBeFalse();
});

test('she remembers facts about the user across conversations', function () {
    $companion = Companion::factory()->create();
    CompanionAgent::fake([
        new ToolCall('call_1', 'remember', ['fact' => 'Has a job interview on Friday']),
        'good luck!!',
        'so how did it go?',
    ]);

    $this->actingAs($companion->user)
        ->postJson(route('chat.messages.store'), ['body' => 'I have an interview Friday', 'channel' => 'text'])
        ->assertOk();

    expect($companion->fresh()->memories)->toBe(['Has a job interview on Friday']);

    $this->postJson(route('chat.messages.store'), ['body' => 'Hey', 'channel' => 'text'])->assertOk();

    CompanionAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Hey')
        && str_contains((string) $prompt->agent->instructions(), '- Has a job interview on Friday'));
});

test('she can call the user', function () {
    $companion = Companion::factory()->create();
    CompanionAgent::fake([
        new ToolCall('call_1', 'start_call', []),
        'pick up!',
    ]);

    $this->actingAs($companion->user)
        ->postJson(route('chat.messages.store'), ['body' => 'Call me?', 'channel' => 'text'])
        ->assertOk()
        ->assertJsonPath('incoming_call', true);
});

test('a message needs text or a photo', function () {
    $companion = Companion::factory()->create();
    CompanionAgent::fake()->preventStrayPrompts();

    $this->actingAs($companion->user)
        ->postJson(route('chat.messages.store'), ['channel' => 'text'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['body' => 'The body field is required when image is not present.']);

    expect($companion->messages()->exists())->toBeFalse();
    CompanionAgent::assertNeverPrompted();
});

test('the user is told when the AI provider is busy', function () {
    $companion = Companion::factory()->create();
    CompanionAgent::fake(fn () => throw RateLimitedException::forProvider('gemini', 429));

    $this->actingAs($companion->user)
        ->postJson(route('chat.messages.store'), ['body' => 'Hello?', 'channel' => 'text'])
        ->assertServiceUnavailable()
        ->assertJsonPath('message', "She's getting a lot of messages right now. Try again in a moment.");

    expect($companion->messages()->sole()->body)->toBe('Hello?');
});

test('starting a call logs it and she greets the user in a single spoken reply', function () {
    $user = User::factory()->create(['name' => 'Sam']);
    $companion = Companion::factory()->for($user)->create(['name' => 'Luna']);
    CompanionAgent::fake(["Hey you!\n\nI was just thinking about you."]);

    $this->actingAs($user)
        ->postJson(route('chat.calls.start'), ['incoming' => true])
        ->assertOk()
        ->assertJsonPath('event.body', 'Luna called Sam')
        ->assertJsonCount(1, 'replies')
        ->assertJsonPath('replies.0.body', "Hey you!\n\nI was just thinking about you.")
        ->assertJsonPath('replies.0.channel', MessageChannel::Call->value);

    CompanionAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('(Luna called Sam)')
        && collect($prompt->agent->tools())->doesntContain(fn (object $tool): bool => $tool instanceof StartCall));
});

test('voice call messages are tagged so she knows she is on a call', function () {
    $companion = Companion::factory()->create();
    CompanionAgent::fake(['I can hear you']);

    $this->actingAs($companion->user)
        ->postJson(route('chat.messages.store'), ['body' => 'Can you hear me?', 'channel' => 'call'])
        ->assertOk();

    CompanionAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('· voice call] Can you hear me?'));
});

test('ending a call logs how long it lasted', function () {
    $companion = Companion::factory()->create();

    $this->actingAs($companion->user)
        ->postJson(route('chat.calls.end'), ['duration' => 151])
        ->assertOk()
        ->assertJsonPath('event.body', 'Call ended · 2:31');

    expect($companion->messages()->sole()->role)->toBe(MessageRole::Event);
});

test('the user can clear their chat history without touching anyone else\'s', function () {
    Storage::fake();
    $companion = Companion::factory()->create();
    Message::factory()->for($companion)->count(2)->create();
    Storage::put("chat/{$companion->id}/photo.jpg", 'photo');
    $otherMessage = Message::factory()->create();

    $this->actingAs($companion->user)
        ->delete(route('chat.messages.destroy'))
        ->assertRedirect(route('companion.edit'));

    expect($companion->messages()->exists())->toBeFalse()
        ->and($otherMessage->fresh())->not->toBeNull();
    Storage::assertMissing("chat/{$companion->id}/photo.jpg");
});
