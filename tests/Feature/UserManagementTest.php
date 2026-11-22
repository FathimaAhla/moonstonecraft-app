<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests are sent to the login page', function () {
    $this->get(route('home'))->assertRedirect('/dashboard');
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->get('/register')->assertNotFound();
});

test('staff cannot manage users', function () {
    $this->actingAs(User::factory()->staff()->create());

    $this->get(route('users.index'))->assertForbidden();
    $this->post(route('users.store'), [])->assertForbidden();
});

test('admin can create a staff user', function () {
    $this->actingAs(User::factory()->create());

    $this->post(route('users.store'), [
        'name' => 'Nimal', 'email' => 'nimal@example.com', 'role' => 'staff', 'password' => 'password-123',
    ])->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'nimal@example.com')->firstOrFail();
    expect($user->role)->toBe(UserRole::Staff)->and($user->email_verified_at)->not->toBeNull();
});

test('admin can edit a user and keep the password when blank', function () {
    $this->actingAs(User::factory()->create());
    $staff = User::factory()->staff()->create();
    $hash = $staff->password;

    $this->put(route('users.update', $staff), [
        'name' => 'Renamed', 'email' => $staff->email, 'role' => 'admin', 'password' => '',
    ])->assertSessionHasNoErrors();

    $staff->refresh();
    expect($staff->name)->toBe('Renamed')->and($staff->role)->toBe(UserRole::Admin)->and($staff->password)->toBe($hash);
});

test('the last admin cannot be demoted or deleted', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin);

    $this->put(route('users.update', $admin), [
        'name' => $admin->name, 'email' => $admin->email, 'role' => 'staff',
    ]);
    $this->delete(route('users.destroy', $admin));

    expect($admin->fresh()->role)->toBe(UserRole::Admin);
});

test('invalid roles are rejected', function () {
    $this->actingAs(User::factory()->create());

    $this->post(route('users.store'), [
        'name' => 'X', 'email' => 'x@example.com', 'role' => 'boss', 'password' => 'password-123',
    ])->assertSessionHasErrors('role');
});
