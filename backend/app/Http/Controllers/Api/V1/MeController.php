<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Actions\ChangePassword;
use App\Domain\Access\Actions\RevokeOtherSessions;
use App\Domain\Access\Actions\UpdateProfile;
use App\Domain\Access\Rules\PasswordPolicy;
use App\Domain\Access\Support\AvatarStore;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Device\Support\MobileAccess;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Api\RequestId;
use App\Support\Phone\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Akun saya di Pakis Pro (05 §5.1: `api.v1.me.*`). Hanya data milik pengguna token; tidak ada ID
 * dari klien (tanpa risiko IDOR).
 */
class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return self::json(self::payload(self::user($request)));
    }

    /**
     * Ubah nomor HP & foto (05 §5.1). Nomor HP adalah kanal pemulihan akun → mengubahnya wajib
     * password saat ini (sama dengan web, ProfileUpdateRequest).
     */
    public function update(Request $request, UpdateProfile $action): JsonResponse
    {
        $user = self::user($request);
        $phone = $request->input('phone', $user->phone);
        $request->merge([
            // Disimpan `62…` lewat normalizer bersama (BR-166(1), T-073), sama dengan Profil web.
            'phone' => PhoneNormalizer::forInput($phone),
        ]);

        $data = $request->validate([
            // HP lama tak sah yang tidak diubah tetap diterima (lihat UserRequest).
            'phone' => ['nullable', 'string', Rule::when($request->input('phone') !== $user->phone, ['regex:'.PhoneNormalizer::STORED_PATTERN])],
            // Isi berkas diperiksa lagi oleh AvatarStore (getimagesize + encode ulang).
            'avatar' => ['nullable', 'file', 'max:'.AvatarStore::MAX_KB, 'mimes:png,jpg,jpeg,webp'],
            'current_password' => ['nullable', 'string'],
        ], [
            'phone.regex' => PhoneNormalizer::INVALID_MESSAGE,
            'avatar.max' => 'Ukuran foto maksimal 2 MB.',
            'avatar.mimes' => 'Foto harus PNG, JPG, atau WebP.',
        ]);

        // HP lama `08…` yang belum ternormalisasi tidak dianggap berubah hanya karena formatnya.
        if ($data['phone'] !== (PhoneNormalizer::normalize($user->phone) ?? $user->phone)) {
            $password = $data['current_password'] ?? null;
            if (! is_string($password) || $password === '' || ! Hash::check($password, $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'Masukkan password saat ini yang benar untuk mengganti nomor HP.']);
            }
        }

        $action->handle($user, [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $data['phone'],
        ], $request->file('avatar'));

        return self::json(self::payload($user->refresh()));
    }

    /** Ganti password (FR-004). Token HP ini tetap hidup; token lain & sesi web dicabut. */
    public function password(Request $request, PasswordPolicy $policy, ChangePassword $action): JsonResponse
    {
        $user = self::user($request);
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'password' => $policy->rules($user),
        ]);

        // Rute ini di belakang EnsureDeviceBound: token selalu PersonalAccessToken perangkat.
        $action->handle($user, (string) $data['password'], SecurityChannel::Api, (int) $user->currentAccessToken()->getKey());

        return self::json(self::payload($user->refresh()));
    }

    /** Cabut semua sesi WEB milik sendiri (05 §5.1 `revoke-others`); token HP ini tidak tersentuh. */
    public function revokeOthers(Request $request, RevokeOtherSessions $action): JsonResponse
    {
        // API tidak punya sesi web "saat ini": semua sesi web akun dicabut.
        $count = $action->handle(self::user($request), '');

        return self::json(['revoked' => $count]);
    }

    /**
     * Data pengguna untuk Pakis Pro (05 §5.3 `user`). `permissions` = ability token (permission
     * mobile), bukan seluruh permission web.
     *
     * @return array<string, mixed>
     */
    public static function payload(User $user): array
    {
        $user->loadMissing('employee.primaryAssignment.position:id,name', 'employee.primaryAssignment.location:id,name');
        $employee = $user->employee;
        $assignment = $employee?->primaryAssignment;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            // URL foto web butuh sesi web; endpoint foto untuk Pakis Pro menyusul (T-043).
            'has_avatar' => $user->avatar_path !== null,
            'must_change_password' => $user->must_change_password,
            'employee' => $employee === null ? null : [
                'id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'position' => $assignment?->position?->name,
                'location' => $assignment?->location?->name,
            ],
            'permissions' => MobileAccess::abilities($user),
        ];
    }

    /**
     * Foto profil milik sendiri untuk Pakis Pro (05 §5.1 `api.v1.me.avatar`, keputusan Mas 16 Nov
     * 2026). Hanya milik pemilik token; tanpa foto → 404. AvatarStore selalu menyimpan WebP.
     */
    public function avatar(Request $request): StreamedResponse
    {
        $path = self::user($request)->avatar_path;
        if ($path === null || ! Storage::disk(AvatarStore::DISK)->exists($path)) {
            throw new ApiError(ErrorCode::NOT_FOUND);
        }

        return Storage::disk(AvatarStore::DISK)->response($path, null, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private static function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /** @param  array<string, mixed>  $data */
    private static function json(array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => RequestId::current()]]);
    }
}
