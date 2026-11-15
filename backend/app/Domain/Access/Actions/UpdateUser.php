<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Notifications\EmailChanged;
use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Ubah akun oleh admin (FR-016): nama, username, email, HP, role. Wewenang atas akun
 * (`users.manage-account`, `users.assign-roles`) sudah dicek controller; batas delegasi role di
 * AssignUserRoles. Email & HP adalah kanal pemulihan akun, jadi seperti di Profil (T-017): email
 * baru dianggap belum terverifikasi (tautan reset tidak dikirim ke sana sebelum diverifikasi,
 * BR-012) dan alamat lama diberi tahu.
 */
final class UpdateUser
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AssignUserRoles $roles,
    ) {}

    /**
     * @param  array{name: string, username: string, email: string|null, phone: string|null, roles: list<string>, employee_id?: int|null}  $data
     */
    public function handle(User $actor, User $target, array $data): void
    {
        $oldEmail = $target->email;

        DB::transaction(function () use ($actor, $target, $data) {
            $before = $target->only(['name', 'username', 'email', 'phone', 'employee_id']);

            $target->fill([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                // Pegawai terkait (T-024); kunci tidak ada = tidak diubah.
                'employee_id' => array_key_exists('employee_id', $data) ? $data['employee_id'] : $target->employee_id,
            ]);

            if ($target->isDirty('email')) {
                $target->email_verified_at = null;
            }

            $dirty = $target->isDirty();
            if ($dirty) {
                $target->updated_by = $actor->id;
                $target->save();
            }

            $roles = $this->roles->handle($actor, $target, $data['roles']);

            if ($dirty || $roles['old'] !== $roles['new']) {
                $this->audit->record('user.updated', $target,
                    [...$before, 'roles' => $roles['old']],
                    [...$target->only(['name', 'username', 'email', 'phone', 'employee_id']), 'roles' => $roles['new']],
                );
            }
        });

        if ($target->email !== $oldEmail) {
            if ($target->email !== null) {
                $target->sendEmailVerificationNotification();
            }

            if ($oldEmail !== null) {
                Notification::route('mail', $oldEmail)->notify(new EmailChanged($target->email, now()));
            }
        }
    }
}
