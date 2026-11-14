<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Notifications\EmailChanged;
use App\Domain\Access\Support\AvatarStore;
use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Profil saya (FR-010): nama, HP, email, avatar. Pemanggil sudah memastikan `current_password`
 * bila email/HP berubah (keduanya kanal pemulihan akun).
 *
 * Email berubah → `email_verified_at` dikosongkan & tautan verifikasi dikirim ke alamat BARU,
 * sedangkan alamat LAMA diberi tahu: bila sesi dibajak, pemilik asli tetap tahu emailnya diganti.
 */
final class UpdateProfile
{
    public function __construct(
        private readonly AvatarStore $avatars,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, email: string|null, phone: string|null}  $data
     */
    public function handle(User $user, array $data, ?UploadedFile $avatar = null, bool $removeAvatar = false): void
    {
        // Berkas baru diproses di luar transaksi (operasi berkas tidak ikut rollback); bila DB gagal,
        // berkas yatim dihapus di catch.
        $newAvatar = $avatar !== null ? $this->avatars->store($avatar) : null;
        $oldAvatar = $user->avatar_path;
        $oldEmail = $user->email;

        try {
            DB::transaction(function () use ($user, $data, $newAvatar, $removeAvatar) {
                $before = $user->only(['name', 'email', 'phone', 'avatar_path']);

                $user->fill([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                ]);

                if ($newAvatar !== null) {
                    $user->avatar_path = $newAvatar;
                } elseif ($removeAvatar) {
                    $user->avatar_path = null;
                }

                if ($user->isDirty('email')) {
                    $user->email_verified_at = null;
                }

                if (! $user->isDirty()) {
                    return;
                }

                // Path avatar acak tidak bermakna di audit; cukup penanda ada/baru/kosong.
                $after = $user->only(['name', 'email', 'phone', 'avatar_path']);
                $avatarChanged = $user->isDirty('avatar_path');
                $before['avatar_path'] = $before['avatar_path'] === null ? null : 'ada';
                $after['avatar_path'] = match (true) {
                    $after['avatar_path'] === null => null,
                    $avatarChanged => 'baru',
                    default => 'ada',
                };

                $user->save();
                $this->audit->record('user.updated', $user, $before, $after);
            });
        } catch (\Throwable $e) {
            $this->avatars->delete($newAvatar);

            throw $e;
        }

        if ($user->avatar_path !== $oldAvatar) {
            $this->avatars->delete($oldAvatar);
        }

        if ($user->email !== $oldEmail) {
            if ($user->email !== null) {
                $user->sendEmailVerificationNotification();
            }

            if ($oldEmail !== null) {
                Notification::route('mail', $oldEmail)->notify(new EmailChanged($user->email, now()));
            }
        }
    }
}
