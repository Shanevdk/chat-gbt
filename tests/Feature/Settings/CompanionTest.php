<?php

use App\Models\Companion;
use App\Models\CompanionPhoto;
use App\Models\Message;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('companion settings page is displayed', function () {
    $companion = Companion::factory()->create(['memories' => ['Loves pizza']]);

    $this->actingAs($companion->user)
        ->get(route('companion.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/companion')
            ->where('companion.name', $companion->name)
            ->where('companion.memories', ['Loves pizza'])
        );
});

test('her profile can be updated', function () {
    $companion = Companion::factory()->create();

    $this->actingAs($companion->user)
        ->patch(route('companion.update'), [
            'name' => 'Luna',
            'personality' => 'Dry humor, loves astronomy.',
            'appearance' => 'Short red hair and green eyes.',
            'voice_name' => 'Microsoft Aria Online (Natural)',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('companion.edit'));

    expect($companion->fresh())
        ->name->toBe('Luna')
        ->personality->toBe('Dry humor, loves astronomy.')
        ->appearance->toBe('Short red hair and green eyes.')
        ->voice_name->toBe('Microsoft Aria Online (Natural)');
});

test('her profile needs a name and personality', function () {
    $companion = Companion::factory()->create();

    $this->actingAs($companion->user)
        ->patch(route('companion.update'), [])
        ->assertSessionHasErrors([
            'name' => 'The name field is required.',
            'personality' => 'The personality field is required.',
        ]);
});

test('her profile picture can be replaced', function () {
    Storage::fake();
    Storage::put('companions/avatars/old.jpg', 'old');
    $companion = Companion::factory()->create(['avatar_path' => 'companions/avatars/old.jpg']);

    $this->actingAs($companion->user)
        ->post(route('companion.avatar.update'), ['avatar' => UploadedFile::fake()->image('new.jpg')])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('companion.edit'));

    Storage::assertExists($companion->fresh()->avatar_path);
    Storage::assertMissing('companions/avatars/old.jpg');
});

test('replacing her profile picture keeps it when a chat message shows it', function () {
    Storage::fake();
    Storage::put('companions/1/selfies/first.jpg', 'selfie');
    $companion = Companion::factory()->create(['avatar_path' => 'companions/1/selfies/first.jpg']);
    Message::factory()->for($companion)->fromCompanion()->create(['image_path' => 'companions/1/selfies/first.jpg']);

    $this->actingAs($companion->user)
        ->post(route('companion.avatar.update'), ['avatar' => UploadedFile::fake()->image('new.jpg')])
        ->assertRedirect(route('companion.edit'));

    Storage::assertExists('companions/1/selfies/first.jpg');
});

test('photos can be added to her camera roll', function () {
    Storage::fake();
    $companion = Companion::factory()->create();

    $this->actingAs($companion->user)
        ->post(route('companion.photos.store'), [
            'photo' => UploadedFile::fake()->image('selfie.jpg'),
            'caption' => 'Mirror selfie',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('companion.edit'));

    $photo = $companion->photos()->sole();

    expect($photo->caption)->toBe('Mirror selfie');
    Storage::assertExists($photo->path);
});

test('camera roll photos must be images', function () {
    $companion = Companion::factory()->create();

    $this->actingAs($companion->user)
        ->post(route('companion.photos.store'), [
            'photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            'caption' => 'Not a photo',
        ])
        ->assertSessionHasErrors(['photo' => 'The photo field must be an image.']);

    expect($companion->photos()->exists())->toBeFalse();
});

test('removing a photo keeps the file while a message still shows it', function () {
    Storage::fake();
    $companion = Companion::factory()->create();
    $sentPhoto = CompanionPhoto::factory()->for($companion)->create();
    $unsentPhoto = CompanionPhoto::factory()->for($companion)->create();
    Storage::put($sentPhoto->path, 'sent');
    Storage::put($unsentPhoto->path, 'unsent');
    Message::factory()->for($companion)->fromCompanion()->create(['image_path' => $sentPhoto->path]);

    $this->actingAs($companion->user);
    $this->delete(route('companion.photos.destroy', $sentPhoto))->assertRedirect(route('companion.edit'));
    $this->delete(route('companion.photos.destroy', $unsentPhoto))->assertRedirect(route('companion.edit'));

    expect($companion->photos()->exists())->toBeFalse();
    Storage::assertExists($sentPhoto->path);
    Storage::assertMissing($unsentPhoto->path);
});

test('users cannot remove photos from someone else\'s camera roll', function () {
    $companion = Companion::factory()->create();
    $otherPhoto = CompanionPhoto::factory()->create();

    $this->actingAs($companion->user)
        ->delete(route('companion.photos.destroy', $otherPhoto))
        ->assertNotFound();

    expect($otherPhoto->fresh())->not->toBeNull();
});

test('her memories can be cleared', function () {
    $companion = Companion::factory()->create(['memories' => ['Loves pizza']]);

    $this->actingAs($companion->user)
        ->delete(route('companion.memories.destroy'))
        ->assertRedirect(route('companion.edit'));

    expect($companion->fresh()->memories)->toBeNull();
});
