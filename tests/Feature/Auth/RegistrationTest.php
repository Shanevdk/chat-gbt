<?php

use App\Models\User;

test('the registration page is not available', function () {
    $this->get('/register')->assertNotFound();
});

test('accounts cannot be created from the web', function () {
    $this->post('/register', [
        'name' => 'Stranger',
        'email' => 'stranger@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
    expect(User::where('email', 'stranger@example.com')->exists())->toBeFalse();
});
