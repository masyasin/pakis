<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Access\Actions\ChangePassword;
use App\Domain\Access\Rules\PasswordPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'passwordRules' => app(PasswordPolicy::class)->toPasswordRulesString(),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $request->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('settings/security', $props);
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request, ChangePassword $action): RedirectResponse
    {
        $action->handle($request->user(), (string) $request->validated('password'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Password diganti. Sesi di perangkat lain sudah dikeluarkan.']);

        return back();
    }
}
