<?php

use App\Models\User;

test('closed registration shows the coming soon page and refuses sign-ups', function () {
    config(['cpd.registration_open' => false]);

    $this->get('/register')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/coming-soon'));

    $this->post('/register', [
        'name' => 'Hopeful User',
        'email' => 'hopeful@example.com',
        'password' => 'password-123!',
        'password_confirmation' => 'password-123!',
    ])->assertForbidden();

    expect(User::where('email', 'hopeful@example.com')->exists())->toBeFalse();
});

test('existing users can still log in while registration is closed', function () {
    config(['cpd.registration_open' => false]);

    $user = ukDoctor();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect();

    $this->actingAs($user)->get('/inbox')->assertOk();
});
