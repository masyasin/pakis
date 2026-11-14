<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Actions\ChangePassword;
use App\Domain\Access\Rules\PasswordPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Halaman wajib ganti password (05 §4.1 `admin.password.force*`, FR-007, SEC-006). */
class ForcePasswordChangeController extends Controller
{
    public function edit(Request $request, PasswordPolicy $policy): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->must_change_password) {
            return to_route('dashboard');
        }

        return Inertia::render('auth/force-change-password', [
            'username' => $user->username,
            'minLength' => PasswordPolicy::minLength(),
            'maxLength' => PasswordPolicy::MAX_LENGTH,
            'passwordRules' => $policy->toPasswordRulesString(),
        ]);
    }

    public function update(Request $request, PasswordPolicy $policy, ChangePassword $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->must_change_password) {
            return to_route('dashboard');
        }

        $data = $request->validate(['password' => $policy->rules($user)], [], ['password' => 'password']);

        $action->handle($user, $data['password']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Password diganti. Selamat bekerja.']);

        return to_route('dashboard');
    }
}
