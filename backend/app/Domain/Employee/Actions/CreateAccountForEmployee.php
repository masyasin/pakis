<?php

namespace App\Domain\Employee\Actions;

use App\Domain\Access\Actions\CreateUser;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Buat akun dari data pegawai (FR-016, 05 `admin.employees.account`). Nama, HP, dan email diambil
 * dari pegawai; pembuatan akun, password awal, audit, dan batas delegasi role memakai CreateUser
 * yang sama dengan form Pengguna, jadi aturannya tidak bercabang.
 */
final class CreateAccountForEmployee
{
    public function __construct(private readonly CreateUser $users) {}

    /**
     * @param  array{username: string, roles: list<string>, password_mode: 'generate'|'email_link'}  $data
     * @param  string  $field  awalan nama field untuk pesan galat (form gabungan memakai `account.`)
     * @return array{user: User, temporary_password: string|null}
     */
    public function handle(User $actor, Employee $employee, array $data, string $field = 'account.'): array
    {
        return DB::transaction(function () use ($actor, $employee, $data, $field) {
            $fail = fn (string $key, string $message) => throw ValidationException::withMessages([$field.$key => $message]);

            // Kunci baris pegawai: dua klik "Buat akun" bersamaan tidak boleh sama-sama lolos.
            $locked = Employee::query()->lockForUpdate()->findOrFail($employee->id);

            if (! $locked->isActive()) {
                $fail('username', 'Pegawai nonaktif tidak bisa dibuatkan akun.');
            }

            // Termasuk akun terhapus: index unik `users_employee_id_unique` tetap mencakupnya.
            if (User::withTrashed()->where('employee_id', $locked->id)->exists()) {
                $fail('username', 'Pegawai ini sudah punya akun.');
            }

            if ($data['password_mode'] === 'email_link' && $locked->email === null) {
                $fail('password_mode', 'Pegawai ini belum punya email. Pilih "Buat password sementara" atau isi email pegawai dulu.');
            }

            if ($locked->email !== null && User::query()->where('email', $locked->email)->exists()) {
                $fail('username', 'Email pegawai ini sudah dipakai akun lain. Ubah email pegawai dulu.');
            }

            try {
                return $this->createUser($actor, $locked, $data);
            } catch (ValidationException $e) {
                // Galat CreateUser (mis. batas delegasi role) memakai nama field form Pengguna;
                // beri awalan agar tampil di field yang benar pada form gabungan.
                throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $key) => [$field.$key => $messages])->all());
            }
        });
    }

    /**
     * @param  array{username: string, roles: list<string>, password_mode: 'generate'|'email_link'}  $data
     * @return array{user: User, temporary_password: string|null}
     */
    private function createUser(User $actor, Employee $locked, array $data): array
    {
        return $this->users->handle($actor, [
            'employee_id' => $locked->id,
            'name' => $locked->full_name,
            'username' => $data['username'],
            'email' => $locked->email,
            'phone' => $locked->phone,
            'is_active' => true,
            'roles' => $data['roles'],
            'password_mode' => $data['password_mode'],
        ]);
    }
}
