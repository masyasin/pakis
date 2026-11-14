<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Support\MfaRequirement;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RecoveryCodesOnce;
use App\Http\Requests\Profile\TwoFactorAuthenticationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

/**
 * Halaman wajib aktivasi MFA (BR-015, 05 §4.1 `admin.mfa.setup`). Aktivasinya sendiri lewat
 * endpoint Fortify `user/two-factor-*` (komponen ManageTwoFactor).
 */
class MfaSetupController extends Controller
{
    public function __invoke(TwoFactorAuthenticationRequest $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $viewable = (bool) $request->session()->get(RecoveryCodesOnce::SESSION_FLAG, false);

        // Aktif atau tidak wajib → tidak ada yang perlu dilakukan, KECUALI tepat setelah aktivasi:
        // halaman tetap tampil agar kode pemulihan (sekali tampil) sempat disimpan.
        if (! MfaRequirement::pending($user) && ! ($viewable && $user->hasEnabledTwoFactorAuthentication())) {
            return to_route('admin.dashboard');
        }

        $request->ensureStateIsValid();

        return Inertia::render('auth/mfa-setup-required', [
            'requiresConfirmation' => Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm'),
            'twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(),
            'recoveryCodesViewable' => $viewable,
        ]);
    }
}
