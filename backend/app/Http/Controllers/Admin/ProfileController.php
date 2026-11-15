<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Actions\ChangePassword;
use App\Domain\Access\Actions\ListSessions;
use App\Domain\Access\Actions\RevokeOtherSessions;
use App\Domain\Access\Actions\RevokeSession;
use App\Domain\Access\Actions\UnlinkTelegram;
use App\Domain\Access\Actions\UpdateProfile;
use App\Domain\Access\Rules\PasswordPolicy;
use App\Domain\Access\Support\AvatarStore;
use App\Domain\Access\Support\MfaRequirement;
use App\Domain\Notification\Telegram\TelegramLink;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RecoveryCodesOnce;
use App\Http\Requests\Profile\CurrentPasswordRequest;
use App\Http\Requests\Profile\PasswordUpdateRequest;
use App\Http\Requests\Profile\ProfileUpdateRequest;
use App\Http\Requests\Profile\TwoFactorAuthenticationRequest;
use App\Models\User;
use App\Support\Time\DisplayTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Profil & Keamanan milik pengguna yang sedang masuk (FR-003, FR-004, FR-010; 05 §4.1).
 * Semua aksi hanya menyentuh akun `$request->user()`: tidak ada parameter id pengguna.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('profile/edit', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(ProfileUpdateRequest $request, UpdateProfile $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $emailBefore = $user->email;

        $action->handle(
            $user,
            $request->profileData(),
            $request->file('avatar'),
            $request->boolean('remove_avatar'),
        );

        $message = $user->email !== $emailBefore && $user->email !== null
            ? 'Profil disimpan. Cek email baru Anda untuk verifikasi.'
            : 'Profil disimpan.';
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('admin.profile.edit');
    }

    /** Avatar disimpan di disk privat (data pribadi), jadi disajikan lewat route ber-auth. */
    public function avatar(Request $request): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $path = $user->avatar_path;

        abort_if($path === null || ! Storage::disk(AvatarStore::DISK)->exists($path), 404);

        return Storage::disk(AvatarStore::DISK)->response($path, null, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function security(TwoFactorAuthenticationRequest $request, ListSessions $sessions): Response
    {
        /** @var User $user */
        $user = $request->user();

        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'passwordRules' => app(PasswordPolicy::class)->toPasswordRulesString(),
            'minLength' => PasswordPolicy::minLength(),
            'maxLength' => PasswordPolicy::MAX_LENGTH,
            'username' => $user->username,
            'sessions' => $sessions->handle($user, $request->session()->getId()),
            'devices' => DeviceController::summaries($user),
            'telegram' => [
                'available' => TelegramLink::available(),
                'linked_at' => $user->telegram_linked_at === null ? null : DisplayTime::toLocal($user->telegram_linked_at)->toIso8601String(),
            ],
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $user->hasEnabledTwoFactorAuthentication();
            // BR-015: role wajib MFA → tombol nonaktifkan disembunyikan (server juga menolak).
            $props['mfaRequired'] = MfaRequirement::requires($user);
            // Kode pemulihan hanya tampil sekali; sesudahnya cukup jumlah sisanya.
            $props['recoveryCodesRemaining'] = $user->hasEnabledTwoFactorAuthentication() ? count($user->recoveryCodes()) : 0;
            $props['recoveryCodesViewable'] = (bool) $request->session()->get(RecoveryCodesOnce::SESSION_FLAG, false);
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('profile/security', $props);
    }

    public function password(PasswordUpdateRequest $request, ChangePassword $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $action->handle($user, (string) $request->validated('password'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Password diganti. Sesi di perangkat lain sudah dikeluarkan.']);

        return back();
    }

    public function destroySession(CurrentPasswordRequest $request, string $session, RevokeSession $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $action->handle($user, $session, $request->session()->getId());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sesi dikeluarkan.']);

        return back();
    }

    public function destroyOtherSessions(CurrentPasswordRequest $request, RevokeOtherSessions $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $count = $action->handle($user, $request->session()->getId());

        Inertia::flash('toast', ['type' => 'success', 'message' => $count > 0 ? "{$count} sesi lain dikeluarkan." : 'Tidak ada sesi lain yang aktif.']);

        return back();
    }

    public function linkTelegram(CurrentPasswordRequest $request, TelegramLink $link): RedirectResponse
    {
        abort_unless(TelegramLink::available(), 404);

        /** @var User $user */
        $user = $request->user();

        // Tautan ditampilkan sebagai tombol di halaman (bukan window.open otomatis yang diblok browser).
        Inertia::flash('telegram_link', $link->create($user));

        return back();
    }

    public function unlinkTelegram(Request $request, UnlinkTelegram $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $action->handle($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Telegram diputus. Kode OTP tidak lagi dikirim ke Telegram.']);

        return back();
    }
}
