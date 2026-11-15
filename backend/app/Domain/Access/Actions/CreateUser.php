<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Notifications\SetPasswordLink;
use App\Domain\Access\Support\PasswordResetBroker;
use App\Domain\Access\Support\TemporaryPassword;
use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Buat akun (FR-016, 07 §4 Pengguna). Password awal:
 * - `generate`: password sementara (BR-014) dikembalikan SEKALI ke pemanggil untuk ditampilkan ke
 *   admin, `must_change_password = true`. Nilainya tidak pernah masuk audit, log, sesi, atau flash.
 * - `email_link`: password acak yang tidak diketahui siapa pun + tautan atur password ke email yang
 *   diisi admin. Email ditandai terverifikasi saat tautan berhasil dipakai (CompletePasswordReset).
 */
final class CreateUser
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AssignUserRoles $roles,
    ) {}

    /**
     * @param  array{name: string, username: string, email: string|null, phone: string|null, is_active: bool, roles: list<string>, password_mode: 'generate'|'email_link', employee_id?: int|null}  $data
     * @return array{user: User, temporary_password: string|null}
     */
    public function handle(User $actor, array $data): array
    {
        $temporary = $data['password_mode'] === 'generate' ? TemporaryPassword::make() : null;

        $user = DB::transaction(function () use ($actor, $data, $temporary) {
            $user = new User;
            $user->forceFill([
                // Pegawai terkait (T-024): dari form Pengguna atau "Buat akun" di halaman pegawai.
                'employee_id' => $data['employee_id'] ?? null,
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'is_active' => $data['is_active'],
                'password' => $temporary ?? Str::password(64),
                'must_change_password' => $temporary !== null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $roles = $this->roles->handle($actor, $user, $data['roles']);

            $this->audit->record('user.created', $user, null, [
                ...$user->only(['name', 'username', 'email', 'phone', 'is_active', 'employee_id']),
                'roles' => $roles['new'],
                'initial_password' => $temporary !== null ? 'sementara' : 'tautan email',
            ]);

            return $user;
        });

        if ($temporary === null && $user->email !== null) {
            // Broker menyimpan hash token & menghapusnya setelah dipakai (SEC-004).
            $user->notify(new SetPasswordLink(PasswordResetBroker::make()->createToken($user)));
        }

        return ['user' => $user, 'temporary_password' => $temporary];
    }
}
