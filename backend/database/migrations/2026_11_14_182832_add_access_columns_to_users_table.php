<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Kolom akun 04 §2.2 (T-013): username (login bersama email, BR-010), status aktif, lockout
 * (BR-016), jejak login, wajib ganti password (T-014), soft delete. FK `employee_id` menyusul saat
 * tabel `employees` dibuat (T-024).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name', 150)->change();
            $table->string('email', 150)->nullable()->change();
            $table->string('username', 50)->nullable()->after('name');
            $table->unsignedBigInteger('employee_id')->nullable()->after('id');
            $table->string('phone', 20)->nullable()->after('email_verified_at');
            $table->string('avatar_path')->nullable()->after('phone');
            $table->boolean('must_change_password')->default(false)->after('password');
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
            $table->boolean('is_active')->default(true)->after('two_factor_confirmed_at');
            $table->timestamp('locked_until')->nullable()->after('is_active');
            $table->timestamp('last_login_at')->nullable()->after('locked_until');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->foreignId('created_by')->nullable()->after('last_login_ip')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->softDeletes();
        });

        // Akun yang sudah ada (data contoh lokal) diberi username dari email/nama agar unik.
        $taken = [];
        foreach (DB::table('users')->orderBy('id')->get(['id', 'name', 'email']) as $user) {
            $base = Str::of($user->email ? Str::before($user->email, '@') : $user->name)
                ->lower()->ascii()->replaceMatches('/[^a-z0-9._-]+/', '.')->trim('.-_')->limit(40, '')->value();
            $base = strlen($base) >= 3 ? $base : 'user'.$user->id;
            $username = $base;
            for ($i = 2; isset($taken[$username]); $i++) {
                $username = $base.$i;
            }
            $taken[$username] = true;
            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable(false)->change();
            $table->unique('username', 'users_username_unique');
            $table->unique('employee_id', 'users_employee_id_unique');
            $table->index(['is_active', 'name'], 'users_active_name_idx');
        });

        // Username huruf kecil saja (04 §2.2); aplikasi juga menormalisasi sebelum menyimpan.
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_username_chk CHECK (username REGEXP '^[a-z0-9._-]{3,50}$')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_username_chk');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropUnique('users_username_unique');
            $table->dropUnique('users_employee_id_unique');
            $table->dropIndex('users_active_name_idx');
            $table->dropSoftDeletes();
            $table->dropColumn([
                'username', 'employee_id', 'phone', 'avatar_path', 'must_change_password', 'password_changed_at',
                'is_active', 'locked_until', 'last_login_at', 'last_login_ip', 'created_by', 'updated_by',
            ]);
        });
    }
};
