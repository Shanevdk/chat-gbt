<?php

use App\MessageChannel;
use App\MessageRole;
use App\Models\Companion;
use App\Models\CompanionPhoto;
use App\Models\Message;
use App\Models\User;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

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
    $requests = fakeClaude(claudeReply([['type' => 'text', 'text' => "heyyy you\n\nhow was the interview??", 'citations' => null]]));

    $response = $this->actingAs($companion->user)->postJson(route('chat.messages.store'), [
        'body' => 'Hi Luna!',
        'channel' => 'text',
        'timezone' => 'Etc/GMT+5',
    ]);

    $response->assertOk()
        ->assertJsonPath('sent.body', 'Hi Luna!')
        ->assertJsonPath('replies.0.body', 'heyyy you')
        ->assertJsonPath('replies.1.body', 'how was the interview??')
        ->assertJsonPath('incoming_call', false);

    expect($companion->messages()->where('role', MessageRole::Assistant)->pluck('body')->all())
        ->toBe(['heyyy you', 'how was the interview??'])
        ->and($companion->fresh()->timezone)->toBe('Etc/GMT+5');

    $request = claudeRequest($requests);

    expect($request['model'])->toBe('claude-opus-5')
        ->and($request['fallbacks'])->toBe('default')
        ->and($requests[0]['request']->getHeaderLine('anthropic-beta'))->toBe('server-side-fallback-2026-07-01')
        ->and($request['system'])->toContain('You are Luna')
        ->and($request['system'])->toContain('Loves astronomy.')
        ->and(end($request['messages'])['content'])->toEndWith('Hi Luna!');
});

test('she can see a photo the user sends', function () {
    Storage::fake();
    $companion = Companion::factory()->create();
    $requests = fakeClaude(claudeReply([['type' => 'text', 'text' => 'omg is that your dog?', 'citations' => null]]));

    $this->actingAs($companion->user)->post(route('chat.messages.store'), [
        'image' => UploadedFile::fake()->image('dog.jpg'),
        'channel' => 'text',
    ], ['Accept' => 'application/json'])->assertOk();

    $sent = $companion->messages()->where('role', MessageRole::User)->sole();
    Storage::assertExists($sent->image_path);

    $content = end(claudeRequest($requests)['messages'])['content'];

    expect($content[0]['type'])->toBe('image')
        ->and($content[0]['source']['media_type'])->toBe('image/jpeg')
        ->and($content[0]['source']['data'])->toBe(base64_encode(Storage::get($sent->image_path)));
});

test('only the three most recent photos are sent as images', function () {
    Storage::fake();
    $companion = Companion::factory()->create();

    foreach (range(1, 4) as $number) {
        Storage::put("chat/photo-{$number}.jpg", UploadedFile::fake()->image("photo-{$number}.jpg")->getContent());
        Message::factory()->for($companion)->create(['body' => "Photo {$number}", 'image_path' => "chat/photo-{$number}.jpg"]);
    }

    $requests = fakeClaude(claudeReply([['type' => 'text', 'text' => 'cute', 'citations' => null]]));

    $this->actingAs($companion->user)
        ->postJson(route('chat.messages.store'), ['body' => 'Thoughts?', 'channel' => 'text'])
        ->assertOk();

    $messages = claudeRequest($requests)['messages'];

    expect($messages[0]['content'])->toBeString()->toContain('(sent a photo) Photo 1')
        ->and($messages[1]['content'][0]['type'])->toBe('image')
        ->and($messages[3]['content'][0]['type'])->toBe('image');
});

test('she can send a photo from her camera roll', function () {
    $companion = Companion::factory()->create();
    $photo = CompanionPhoto::factory()->for($companion)->create(['caption' => 'Beach selfie']);
    $requests = fakeClaude(
        claudeReply([
            ['type' => 'text', 'text' => 'here you go', 'citations' => null],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'send_photo', 'input' => ['photo_id' => $photo->id]],
        ], 'tool_use'),
        claudeReply([['type' => 'text', 'text' => 'miss the beach', 'citations' => null]]),
    );

    $this->actingAs($companion->user)
        ->postJson(route('chat.messages.store'), ['body' => 'Send me a pic', 'channel' => 'text'])
        ->assertOk()
        ->assertJsonCount(3, 'replies')
        ->assertJsonPath('replies.1.image_caption', 'Beach selfie')
        ->assertJsonPath('replies.2.body', 'miss the beach');

    expect($companion->messages()->whereNotNull('image_path')->sole()->image_path)->toBe($photo->path)
        ->and(claudeRequest($requests)['tools'][1]['description'])->toContain("{$photo->id}: Beach selfie")
        ->and(end(claudeRequest($requests, 1)['messages'])['content'][0])->toMatchArray([
            'type' => 'tool_result',
            'tool_use_id' => 'toolu_1',
            'content' => 'Photo sent.',
        ]);
});

test('she cannot send a photo from another companion\'s camera roll', function () {
    $companion = Companion::factory()->create();
    CompanionPhoto::factory()->for($companion)->create();
    $otherPhoto = CompanionPhoto::factory()->create();
    $requests = fakeClaude(
        claudeReply([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'send_photo', 'input' => ['photo_id' => $otherPhoto->id]]], 'tool_use'),
        claudeReply([['type' => 'text', 'text' => 'hmm my camera is acting up', 'citations' => null]]),
    );

    $this->actingAs($companion->user)
        ->postJson(route('chat.messages.store'), ['body' => 'Send me a pic', 'channel' => 'text'])
        ->assertOk()
        ->assertJsonCount(1, 'replies');

    expect($companion->messages()->whereNotNull('image_path')->exists())->toBeFalse()
        ->and(end(claudeRequest($requests, 1)['messages'])['content'][0]['content'])->toBe('That photo is not in your camera roll.');
});

test('she remembers facts about the user across conversations', function () {
    $companion = Companion::factory()->create();
    $requests = fakeClaude(
        claudeReply([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'remember', 'input' => ['fact' => 'Has a job interview on Friday']]], 'tool_use'),
        claudeReply([['type' => 'text', 'text' => 'good luck!!', 'citations' => null]]),
        claudeReply([['type' => 'text', 'text' => 'so how did it go?', 'citations' => null]]),
    );

    $this->actingAs($companion->user)
        ->postJson(route('chat.messages.store'), ['body' => 'I have an interview Friday', 'channel' => 'text'])
        ->assertOk();

    expect($companion->fresh()->memories)->toBe(['Has a job interview on Friday']);

    $this->postJson(route('chat.messages.store'), ['body' => 'Hey', 'channel' => 'text'])->assertOk();

    expect(claudeRequest($requests, 2)['system'])->toContain('- Has a job interview on Friday');
});

test('she can call the user', function () {
    $companion = Companion::factory()->create();
    fakeClaude(
        claudeReply([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'start_call', 'input' => []]], 'tool_use'),
        claudeReply([['type' => 'text', 'text' => 'pick up!', 'citations' => null]]),
    );

    $this->actingAs($companion->user)
        ->postJson(route('chat.messages.store'), ['body' => 'Call me?', 'channel' => 'text'])
        ->assertOk()
        ->assertJsonPath('incoming_call', true);
});

test('a message needs text or a photo', function () {
    $companion = Companion::factory()->create();

    $this->actingAs($companion->user)
        ->postJson(route('chat.messages.store'), ['channel' => 'text'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['body' => 'The body field is required when image is not present.']);

    expect($companion->messages()->exists())->toBeFalse();
});

test('the user is told when Claude rejects the API key', function () {
    $companion = Companion::factory()->create();
    fakeClaude(new Response(401, ['Content-Type' => 'application/json'], (string) json_encode([
        'type' => 'error',
        'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key'],
    ])));

    $this->actingAs($companion->user)
        ->postJson(route('chat.messages.store'), ['body' => 'Hello?', 'channel' => 'text'])
        ->assertServiceUnavailable()
        ->assertJsonPath('message', 'Claude needs a valid API key. Set ANTHROPIC_API_KEY in your .env file.');

    expect($companion->messages()->sole()->body)->toBe('Hello?');
});

test('starting a call logs it and she greets the user in a single spoken reply', function () {
    $user = User::factory()->create(['name' => 'Sam']);
    $companion = Companion::factory()->for($user)->create(['name' => 'Luna']);
    $requests = fakeClaude(claudeReply([['type' => 'text', 'text' => "Hey you!\n\nI was just thinking about you.", 'citations' => null]]));

    $this->actingAs($user)
        ->postJson(route('chat.calls.start'), ['incoming' => true])
        ->assertOk()
        ->assertJsonPath('event.body', 'Luna called Sam')
        ->assertJsonCount(1, 'replies')
        ->assertJsonPath('replies.0.body', "Hey you!\n\nI was just thinking about you.")
        ->assertJsonPath('replies.0.channel', MessageChannel::Call->value);

    $toolNames = array_column(claudeRequest($requests)['tools'], 'name');

    expect($toolNames)->not->toContain('start_call');
});

test('voice call messages are tagged so she knows she is on a call', function () {
    $companion = Companion::factory()->create();
    $requests = fakeClaude(claudeReply([['type' => 'text', 'text' => 'I can hear you', 'citations' => null]]));

    $this->actingAs($companion->user)
        ->postJson(route('chat.messages.store'), ['body' => 'Can you hear me?', 'channel' => 'call'])
        ->assertOk();

    expect(end(claudeRequest($requests)['messages'])['content'])->toContain('· voice call] Can you hear me?');
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
