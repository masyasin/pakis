<?php

namespace App\Domain\Settings\Actions;

use App\Domain\Access\Support\RoleCatalog;
use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Actions\CloseOpeningBalance;
use App\Domain\Settings\Branding\ImageProcessor;
use App\Domain\Settings\SettingDefinition;
use App\Domain\Settings\SettingRegistry;
use App\Domain\Settings\SettingStore;
use App\Domain\Settings\SettingType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Simpan satu grup setting (BR-020, BR-022–BR-024).
 *
 * Payload memakai nama field (`general__app_name`, lihat SettingDefinition::field()). Kunci yang
 * tidak terdaftar atau bukan milik grup ini → 422. Semua nilai grup divalidasi bersama (aturan
 * lintas kunci, mis. versi terbaru ≥ minimum), lalu ditulis atomik dan diaudit per kunci.
 *
 * Penanda PA-19 ditegakkan di sini agar berlaku untuk semua pemanggil, bukan hanya form:
 * kunci `locked` hanya boleh diubah super_admin dengan konfirmasi password baru; kunci `one_way`
 * menolak false → true (termasuk lewat "kembalikan default").
 */
final class UpdateSettingGroup
{
    public function __construct(
        private readonly SettingStore $store,
        private readonly AuditLogger $audit,
        private readonly ImageProcessor $images,
    ) {}

    /**
     * @param  array<string, mixed>  $input  nilai per field
     * @param  list<string>  $reset  field yang dikembalikan ke default
     * @param  array<string, UploadedFile>  $files  unggahan per field (tipe image)
     * @param  bool  $passwordConfirmed  konfirmasi password masih berlaku (PasswordConfirmation::isRecent)
     */
    public function handle(string $group, array $input, array $reset, array $files, ?int $userId, bool $passwordConfirmed = false): void
    {
        $definitions = SettingRegistry::group($group);
        if ($definitions === []) {
            throw ValidationException::withMessages(['group' => 'Grup pengaturan tidak dikenal.']);
        }

        $byField = collect($definitions)->keyBy(fn (SettingDefinition $d) => $d->field());

        $unknown = array_diff([...array_keys($input), ...$reset, ...array_keys($files)], $byField->keys()->all());
        if ($unknown !== []) {
            throw ValidationException::withMessages(
                collect($unknown)->mapWithKeys(fn ($f) => [$f => 'Pengaturan ini tidak dikenal.'])->all(),
            );
        }

        // Berkas hanya untuk field gambar. Tanpa ini, path gambar bisa menjadi nilai setting angka
        // (mis. retention.audit_years → 0) dan audit:purge menghapus seluruh audit.
        $notImage = array_filter(array_keys($files), fn ($field) => $byField[$field]->type !== SettingType::Image);
        if ($notImage !== []) {
            throw ValidationException::withMessages(
                collect($notImage)->mapWithKeys(fn ($f) => [$f => 'Field ini tidak menerima berkas.'])->all(),
            );
        }

        $current = $this->store->group($group);
        $values = [];
        foreach ($input as $field => $value) {
            $definition = $byField[$field];
            if ($definition->type === SettingType::Image) {
                continue; // gambar hanya lewat $files atau reset
            }
            $values[$definition->key] = self::normalize($definition, $value);
        }

        $this->validate($byField->all(), $values, [...$current, ...$values]);

        foreach ($files as $field => $file) {
            $definition = $byField[$field];
            $values[$definition->key] = $this->images->store($file, $field, favicon: $definition->key === 'branding.favicon');
        }

        $resetKeys = array_map(fn ($field) => $byField[$field]->key, $reset);
        $values = array_diff_key($values, array_flip($resetKeys));

        // Hanya yang benar-benar berubah yang ditulis & diaudit.
        $changed = array_filter($values, fn ($value, $key) => $current[$key] !== $definitions[$key]->cast($value), ARRAY_FILTER_USE_BOTH);
        $resetKeys = array_values(array_filter($resetKeys, fn ($key) => $this->store->isCustomized($key)));

        $after = [];
        foreach ($changed as $key => $value) {
            $after[$key] = $definitions[$key]->cast($value);
        }
        foreach ($resetKeys as $key) {
            $after[$key] = $definitions[$key]->cast($definitions[$key]->default);
        }
        $this->guardFlags($definitions, $current, $after, $userId, $passwordConfirmed);

        DB::transaction(function () use ($changed, $resetKeys, $current, $definitions, $userId, $after) {
            $this->store->write($changed, $resetKeys, $userId);

            // T-086 (BR-170): mematikan "saldo awal masih dibuka" = tutup saldo awal; audit penutupan
            // ditulis atomik bersama setting. Di-resolve di sini (bukan konstruktor) agar domain
            // Settings tidak memuat Inventory untuk grup lain.
            if (($after[CloseOpeningBalance::SETTING_KEY] ?? null) === false) {
                app(CloseOpeningBalance::class)->handle($userId);
            }

            foreach ($changed as $key => $value) {
                $this->audit->record('setting.updated', null, [$key => $current[$key]], [$key => $definitions[$key]->cast($value)]);
            }
            foreach ($resetKeys as $key) {
                $this->audit->record('setting.updated', null, [$key => $current[$key]], [$key => $definitions[$key]->cast($definitions[$key]->default)]);
            }
        });
    }

    /**
     * @param  array<string, SettingDefinition>  $definitions
     * @param  array<string, mixed>  $current  nilai efektif sebelum disimpan
     * @param  array<string, mixed>  $after  kunci yang benar-benar berubah => nilai barunya
     */
    private function guardFlags(array $definitions, array $current, array $after, ?int $userId, bool $passwordConfirmed): void
    {
        $locked = array_values(array_filter(array_keys($after), fn ($key) => $definitions[$key]->locked));
        if ($locked !== []) {
            $actor = $userId === null ? null : User::query()->find($userId);
            if ($actor === null || ! $actor->hasRole(RoleCatalog::SUPER_ADMIN)) {
                // Form menonaktifkan field ini untuk selain super_admin; permintaan yang tetap
                // mengubahnya adalah permintaan rekayasa → 403, bukan galat isian.
                throw new AuthorizationException('Hanya Super Admin yang bisa mengubah pengaturan terkunci.');
            }
            if (! $passwordConfirmed) {
                throw ValidationException::withMessages(collect($locked)->mapWithKeys(fn ($key) => [
                    $definitions[$key]->field() => 'Konfirmasi password dulu untuk mengubah pengaturan terkunci.',
                ])->all());
            }
        }

        // Mematikan kunci one_way tidak bisa dibatalkan (mis. tutup saldo awal), jadi wajib konfirmasi password.
        $turnedOff = array_values(array_filter(array_keys($after), fn ($key) => $definitions[$key]->oneWay
            && $current[$key] === true && $after[$key] === false));
        if ($turnedOff !== [] && ! $passwordConfirmed) {
            throw ValidationException::withMessages(collect($turnedOff)->mapWithKeys(fn ($key) => [
                $definitions[$key]->field() => 'Konfirmasi password dulu. Pengaturan ini tidak bisa dinyalakan lagi setelah dimatikan.',
            ])->all());
        }

        $reopened = array_values(array_filter(array_keys($after), fn ($key) => $definitions[$key]->oneWay
            && $current[$key] === false && $after[$key] === true));
        if ($reopened !== []) {
            throw ValidationException::withMessages(collect($reopened)->mapWithKeys(fn ($key) => [
                $definitions[$key]->field() => 'Pengaturan ini sudah dimatikan dan tidak bisa diaktifkan lagi.',
            ])->all());
        }
    }

    /**
     * @param  array<string, SettingDefinition>  $byField
     * @param  array<string, mixed>  $values  kunci => nilai yang dikirim
     * @param  array<string, mixed>  $groupValues  nilai grup setelah perubahan (untuk aturan lintas kunci)
     */
    private function validate(array $byField, array $values, array $groupValues): void
    {
        $rules = [];
        $data = [];
        $attributes = [];
        foreach ($byField as $field => $definition) {
            if (! array_key_exists($definition->key, $values)) {
                continue;
            }
            $data[$field] = $values[$definition->key];
            $rules[$field] = $definition->validationRules();
            if ($definition->type->itemRules() !== []) {
                $rules[$field.'.*'] = $definition->type->itemRules();
            }
            $attributes[$field] = $definition->label;
        }

        $validator = Validator::make($data, $rules, [], $attributes);

        $validator->after(function ($validator) use ($byField, $values, $groupValues) {
            foreach ($byField as $field => $definition) {
                if ($definition->check === null || ! array_key_exists($definition->key, $values)) {
                    continue;
                }
                if ($validator->errors()->has($field)) {
                    continue;
                }
                $message = ($definition->check)($values[$definition->key], $groupValues);
                if ($message !== null) {
                    $validator->errors()->add($field, $message);
                }
            }
        });

        $validator->validate();
    }

    /**
     * Rapikan nilai form: string kosong → null, daftar email dari teks per baris, angka dari teks.
     */
    private static function normalize(SettingDefinition $definition, mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        return match ($definition->type) {
            SettingType::EmailList => array_values(array_filter(array_map('trim', is_array($value) ? $value : (preg_split('/[\r\n,]+/', (string) $value) ?: [])))),
            SettingType::MultiEnum => array_values(array_unique((array) $value)),
            SettingType::Bool => is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            SettingType::Int => is_numeric($value) && (string) (int) $value === (string) $value ? (int) $value : $value,
            SettingType::Enum => is_numeric($value) && is_int($definition->options[0] ?? null) ? (int) $value : $value,
            SettingType::Color => is_string($value) ? strtoupper($value) : $value,
            // Form mengirim teks "90, 60, 30"; butir bukan bilangan bulat dibiarkan agar ditolak validasi.
            SettingType::IntList => $value === null || $value === '' ? [] : array_values(array_map(
                fn ($item) => is_string($item) && preg_match('/^-?\d+$/', trim($item)) === 1 ? (int) trim($item) : $item,
                is_array($value) ? $value : (preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: []),
            )),
            // Angka JSON → string kanonik; float tidak pernah disimpan.
            SettingType::Decimal, SettingType::Money => is_int($value) || is_float($value) ? (string) $value : ($value === '' ? null : $value),
            default => $value === '' ? null : $value,
        };
    }
}
