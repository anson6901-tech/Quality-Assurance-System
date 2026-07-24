<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('a user with a temporary password must change it before using the application', function () {
    $user = User::factory()->create([
        'must_change_password' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/quality/users')
        ->assertRedirect(route('profile.edit'));
});

test('changing the temporary password unlocks the application', function () {
    $user = User::factory()->create([
        'password' => 'password',
        'must_change_password' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'Personal-password-2026',
            'password_confirmation' => 'Personal-password-2026',
        ])
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->must_change_password)->toBeFalse()
        ->and(Hash::check('Personal-password-2026', $user->password))->toBeTrue();
});
