<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Actions\AttemptLogin;
use App\Domain\Access\Actions\CompletePasswordReset;
use App\Domain\Access\Actions\RequestPasswordReset;
use App\Domain\Access\Rules\PasswordPolicy;
use App\Domain\Access\Support\MfaRequirement;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Domain\Device\Actions\RegisterDeviceOnLogin;
use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Domain\Device\Support\DeviceTokens;
use App\Domain\Device\Support\MobileAccess;
use App\Domain\Device\Support\SemVer;
use App\Domain\Notification\Otp\OtpService;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Api\RequestId;
use App\Support\Http\ClientNetwork;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Autentikasi Pakis Pro (05 §5.1, §5.3; FR-002, FR-003, FR-005, FR-006; BR-040). Aturan kredensial
 * sama persis dengan web (AttemptLogin::verify): satu pesan untuk semua kegagalan (SEC-001),
 * lockout BR-016. Urutan sesudah kredensial benar: akses mobile → MFA → perangkat → token.
 */
class AuthController extends Controller
{
    /** Batas tebakan kode MFA per akun per menit (setara limiter `two-factor` web). */
    private const MFA_ATTEMPTS_PER_MINUTE = 5;

    public function login(
        Request $request,
        AttemptLogin $attempt,
        RegisterDeviceOnLogin $register,
        DeviceTokens $tokens,
        SecurityLogger $security,
    ): JsonResponse {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:200'],
            'otp' => ['nullable', 'string', 'max:30'],
            'device' => ['required', 'array'],
            'device.uid' => ['required', 'string', 'min:16', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'device.model' => ['nullable', 'string', 'max:100'],
            'device.os_version' => ['nullable', 'string', 'max:40'],
        ]);

        $result = $attempt->verify($data['login'], $data['password'], (string) $request->ip(), SecurityChannel::Api);
        if ($result->lockedSeconds !== null) {
            throw new ApiError(ErrorCode::ACCOUNT_LOCKED, ['retry_after_s' => $result->lockedSeconds]);
        }
        $user = $result->user ?? throw new ApiError(ErrorCode::INVALID_CREDENTIALS);

        if (! MobileAccess::allows($user)) {
            throw new ApiError(ErrorCode::FORBIDDEN, publicMessage: 'Akun ini tidak memakai aplikasi Pakis Pro.');
        }

        $this->checkMfa($user, $data['otp'] ?? null, $security);

        $device = $register->handle($user, $data['device'], SemVer::normalize($request->header('X-App-Version')));
        if ($device->status !== DeviceStatus::Active) {
            throw new ApiError(ErrorCode::DEVICE_PENDING);
        }

        $token = $tokens->issue($user, $device);
        $device->forceFill(['last_seen_at' => now()])->saveQuietly();
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->saveQuietly();
        $security->record(SecurityEventType::LoginSuccess, SecurityChannel::Api, $user, $user->username, ['device_id' => $device->id]);

        return self::json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => MeController::payload($user),
            'device' => ['status' => $device->status->value],
        ]);
    }

    public function logout(Request $request, SecurityLogger $security): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        // Rute ini di belakang EnsureDeviceBound: token selalu PersonalAccessToken perangkat.
        $token = $user->currentAccessToken();
        Device::query()->where('token_id', $token->getKey())->update(['token_id' => null]);
        $token->delete();
        $security->record(SecurityEventType::Logout, SecurityChannel::Api, $user, $user->username);

        return self::json(['message' => 'Anda sudah keluar.']);
    }

    /** Lupa password (FR-005): respons SELALU sama, apa pun hasilnya (BR-012, SEC-004). */
    public function forgot(Request $request, RequestPasswordReset $action): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:150'],
            'channel' => ['required', 'string', Rule::in(ForgotPasswordController::channels())],
        ]);

        try {
            $action->handle($data['login'], $data['channel'], $request->ip());
        } catch (ValidationException $e) {
            // Pembatas RequestPasswordReset (per identifier/jaringan/jeda OTP) → kode kontrak 429.
            throw new ApiError(ErrorCode::RATE_LIMITED, ['retry_after_s' => 60], $e->validator->errors()->first());
        }

        return self::json(['message' => RequestPasswordReset::SENT]);
    }

    /** Reset password dengan kode OTP (FR-006). Pesan kode salah seragam untuk akun yang tidak ada. */
    public function resetOtp(Request $request, OtpService $otp, PasswordPolicy $policy, CompletePasswordReset $complete): JsonResponse
    {
        $request->validate([
            'login' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'regex:/^\d{6,8}$/'],
        ], ['code.*' => ForgotPasswordController::INVALID_CODE]);

        $identifier = AttemptLogin::normalize($request->input('login'));
        $attemptKey = 'pw-otp-verify:'.sha1(Str::lower(Str::ascii($identifier)).'|'.ClientNetwork::key($request->ip()));
        if (RateLimiter::hit($attemptKey, 900) > 10) {
            throw new ApiError(ErrorCode::RATE_LIMITED, ['retry_after_s' => RateLimiter::availableIn($attemptKey)]);
        }

        $user = User::query()
            ->where(fn ($q) => $q->where('username', $identifier)->orWhere('email', $identifier))
            ->first();

        if ($user === null || ! $otp->check($user, (string) $request->input('code'))) {
            throw ValidationException::withMessages(['code' => ForgotPasswordController::INVALID_CODE]);
        }

        $data = $request->validate(['password' => $policy->rules($user)]);

        $otp->consume($user);
        $complete->handle($user, $data['password'], 'otp');

        return self::json(['message' => 'Password baru tersimpan. Silakan masuk.']);
    }

    /**
     * MFA (BR-015): role wajib MFA tanpa MFA aktif → aktivasi di web; MFA aktif (wajib atau
     * sukarela) → kode authenticator atau kode pemulihan. Tebakan dibatasi per akun.
     */
    private function checkMfa(User $user, ?string $otp, SecurityLogger $security): void
    {
        if (MfaRequirement::pending($user)) {
            throw new ApiError(ErrorCode::MFA_SETUP_REQUIRED);
        }

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return;
        }

        $code = trim((string) $otp);
        if ($code === '') {
            throw new ApiError(ErrorCode::MFA_REQUIRED, ['mfa' => true]);
        }

        // Hitung DULU (atomik di cache), baru verifikasi: request bersamaan tidak bisa sama-sama lolos
        // pengecekan lalu menebak lebih dari batas. Berhasil → penghitung dikosongkan.
        $limiterKey = 'api-mfa:'.$user->id;
        if (RateLimiter::hit($limiterKey, 60) > self::MFA_ATTEMPTS_PER_MINUTE) {
            throw new ApiError(ErrorCode::RATE_LIMITED, ['retry_after_s' => RateLimiter::availableIn($limiterKey)]);
        }

        $secret = Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret);
        $valid = app(TwoFactorAuthenticationProvider::class)->verify($secret, preg_replace('/\s+/', '', $code));

        // Kode pemulihan hanya bila sudah pernah dibuat (kolom null → tidak didekripsi).
        if (! $valid && $user->two_factor_recovery_codes !== null) {
            $recovery = collect($user->recoveryCodes())->first(fn ($c) => hash_equals((string) $c, $code));
            if ($recovery !== null) {
                $user->replaceRecoveryCode($recovery);
                $valid = true;
            }
        }

        if (! $valid) {
            $security->record(SecurityEventType::MfaFailed, SecurityChannel::Api, $user, $user->username);

            throw new ApiError(ErrorCode::MFA_INVALID);
        }

        RateLimiter::clear($limiterKey);
    }

    /** @param  array<string, mixed>  $data */
    private static function json(array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => RequestId::current()]]);
    }
}
