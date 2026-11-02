<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('it creates a verified account from options', function () {
    $this->artisan('app:create-user', [
        '--name' => 'Shane',
        '--email' => 'shane@example.com',
        '--password' => 'a-good-password',
    ])
        ->expectsOutputToContain('Created an account for shane@example.com.')
        ->assertSuccessful();

    $user = User::where('email', 'shane@example.com')->sole();

    expect($user->name)->toBe('Shane')
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('a-good-password', $user->password))->toBeTrue();
});

test('it asks for anything that was not passed in', function () {
    $this->artisan('app:create-user')
        ->expectsQuestion('Name', 'Shane')
        ->expectsQuestion('Email', 'shane@example.com')
        ->expectsQuestion('Password', 'a-good-password')
        ->assertSuccessful();

    expect(Hash::check('a-good-password', User::where('email', 'shane@example.com')->sole()->password))->toBeTrue();
});

test('it generates a password when running without prompts', function () {
    $this->artisan('app:create-user', [
        '--name' => 'Shane',
        '--email' => 'shane@example.com',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Change this password after logging in')
        ->assertSuccessful();

    expect(User::where('email', 'shane@example.com')->exists())->toBeTrue();
});

test('it needs a name and email when running without prompts', function () {
    $this->artisan('app:create-user', ['--name' => 'Shane', '--no-interaction' => true])
        ->expectsOutputToContain('Pass --name and --email when running without prompts.')
        ->assertFailed();

    expect(User::count())->toBe(0);
});

test('it refuses an email that already has an account', function () {
    User::factory()->create(['email' => 'shane@example.com']);

    $this->artisan('app:create-user', [
        '--name' => 'Shane',
        '--email' => 'shane@example.com',
        '--password' => 'a-good-password',
    ])
        ->expectsOutputToContain('The email has already been taken.')
        ->assertFailed();

    expect(User::count())->toBe(1);
});
