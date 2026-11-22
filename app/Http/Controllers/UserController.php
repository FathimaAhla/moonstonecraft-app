<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    use PasswordValidationRules, ProfileValidationRules;

    public function index(): Response
    {
        return Inertia::render('users/index', [
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email', 'role', 'created_at']),
            'roles' => array_column(UserRole::cases(), 'value'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            ...$this->profileRules(),
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['required', 'string', Password::default()],
        ]);

        User::query()->forceCreate($data + ['email_verified_at' => now()]);
        $this->toast('User added.');

        return back();
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            ...$this->profileRules($user->id),
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['nullable', 'string', Password::default()],
        ]);

        if ($user->isAdmin() && $data['role'] !== UserRole::Admin->value && $this->isLastAdmin($user)) {
            $this->toast('There must be at least one admin.', 'error');

            return back();
        }

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $user->update($data);
        $this->toast('User updated.');

        return back();
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            $this->toast('You cannot delete your own account here.', 'error');
        } elseif ($user->isAdmin() && $this->isLastAdmin($user)) {
            $this->toast('There must be at least one admin.', 'error');
        } else {
            $user->delete();
            $this->toast('User deleted.');
        }

        return back();
    }

    private function isLastAdmin(User $user): bool
    {
        return ! User::query()->where('role', UserRole::Admin->value)->whereKeyNot($user->id)->exists();
    }
}
