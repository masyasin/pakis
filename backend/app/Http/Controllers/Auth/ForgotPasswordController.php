<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Access\Actions\AttemptLogin;
use App\Domain\Access\Actions\CompletePasswordReset;
use App\Domain\Access\Actions\RequestPasswordReset;
use App\Domain\Access\Rules\PasswordPolicy;
use App\Domain\Access\Support\PasswordResetBroker;
use App\Domain\Notification\Otp\OtpManager;
use App\Domain\Notification\Otp\OtpService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lupa password (05 §4.1: `password.request|email|reset|update|otp|otp.update`; FR-005, FR-006).
 *
 * Urutan penting di kedua alur reset: token/OTP diperiksa DULU, baru kebijakan password. Kalau
 * dibalik, orang yang hanya tahu email korban bisa menebak password lamanya lewat pesan
 * "tidak boleh sama dengan password saat ini".
 */
class ForgotPasswordController extends Controller
{
    public const INVALID_LINK = 'Tautan reset tidak berlaku atau sudah kedaluwarsa. Minta tautan baru.';

    public const INVALID_CODE = 'Kode salah atau sudah tidak berlaku.';

    private const LABELS = ['email' => 'Email', 'whatsapp' => 'WhatsApp', 'telegram' => 'Telegram'];

    public function create(Request $request): Response
    {
        return Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
            'channels' => collect(self::channels())->map(fn ($c) => ['value' => $c, 'label' => self::LABELS[$c]])->values(),
        ]);
    }

    public function store(Request $request, RequestPasswordReset $action): RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:150'],
            'channel' => ['required', Rule::in(self::channels())],
        ], ['channel.in' => 'Kanal ini tidak tersedia.'], ['login' => 'username atau email', 'channel' => 'kanal']);

        $action->handle($data['login'], $data['channel'], $request->ip());

        if ($data['channel'] === 'email') {
            return back()->with('status', RequestPasswordReset::SENT);
        }

        // Kanal OTP: lanjut ke halaman isi kode (untuk akun yang ada maupun tidak — respons sama).
        $request->session()->put('password_otp.login', AttemptLogin::normalize($data['login']));

        return to_route('password.otp')->with('status', RequestPasswordReset::SENT);
    }

    public function edit(Request $request, PasswordPolicy $policy, string $token): Response
    {
        return Inertia::render('auth/reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
            'minLength' => PasswordPolicy::minLength(),
            'passwordRules' => $policy->toPasswordRulesString(),
        ]);
    }

    public function update(Request $request, PasswordPolicy $policy, CompletePasswordReset $complete): RedirectResponse
    {
        $request->validate(['token' => ['required', 'string'], 'email' => ['required', 'string', 'max:150']]);

        $user = User::query()->where('email', mb_strtolower(trim((string) $request->input('email'))))->first();
        $broker = PasswordResetBroker::make();

        if ($user === null || ! $broker->tokenExists($user, (string) $request->input('token'))) {
            throw ValidationException::withMessages(['email' => self::INVALID_LINK]);
        }

        $data = $request->validate(['password' => $policy->rules($user)]);

        $broker->deleteToken($user); // sekali pakai
        $complete->handle($user, $data['password'], 'email');

        return to_route('login')->with('status', 'Password baru tersimpan. Silakan masuk.');
    }

    public function editOtp(Request $request, PasswordPolicy $policy): Response
    {
        return Inertia::render('auth/reset-password-otp', [
            'status' => $request->session()->get('status'),
            'login' => (string) $request->session()->get('password_otp.login', ''),
            'codeLength' => (int) setting('auth.otp_length'),
            'minLength' => PasswordPolicy::minLength(),
            'passwordRules' => $policy->toPasswordRulesString(),
        ]);
    }

    public function updateOtp(Request $request, PasswordPolicy $policy, OtpService $otp, CompletePasswordReset $complete): RedirectResponse
    {
        $request->validate([
            'login' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'regex:/^\d{6,8}$/'],
        ], ['code.*' => self::INVALID_CODE]);

        $identifier = AttemptLogin::normalize($request->input('login'));
        $user = User::query()
            ->where(fn ($q) => $q->where('username', $identifier)->orWhere('email', $identifier))
            ->first();

        if ($user === null || ! $otp->check($user, (string) $request->input('code'))) {
            throw ValidationException::withMessages(['code' => self::INVALID_CODE]);
        }

        $data = $request->validate(['password' => $policy->rules($user)]);

        $otp->consume($user);
        $complete->handle($user, $data['password'], 'otp');
        $request->session()->forget('password_otp.login');

        return to_route('login')->with('status', 'Password baru tersimpan. Silakan masuk.');
    }

    /**
     * Kanal yang ditawarkan = setting `auth.forgot_channels` ∩ kanal yang drivernya tersedia.
     *
     * @return list<string>
     */
    public static function channels(): array
    {
        $enabled = (array) setting('auth.forgot_channels');

        return array_values(array_filter(
            ['email', 'whatsapp', 'telegram'],
            fn (string $c) => in_array($c, $enabled, true) && ($c === 'email' || OtpManager::available($c)),
        ));
    }
}
