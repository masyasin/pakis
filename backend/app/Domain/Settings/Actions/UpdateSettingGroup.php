<?php

namespace App\Domain\Settings\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Settings\Branding\ImageProcessor;
use App\Domain\Settings\SettingDefinition;
use App\Domain\Settings\SettingRegistry;
use App\Domain\Settings\SettingStore;
use App\Domain\Settings\SettingType;
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
     */
    public function handle(string $group, array $input, array $reset, array $files, ?int $userId): void
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

        DB::transaction(function () use ($changed, $resetKeys, $current, $definitions, $userId) {
            $this->store->write($changed, $resetKeys, $userId);

            foreach ($changed as $key => $value) {
                $this->audit->record('setting.updated', null, [$key => $current[$key]], [$key => $definitions[$key]->cast($value)]);
            }
            foreach ($resetKeys as $key) {
                $this->audit->record('setting.updated', null, [$key => $current[$key]], [$key => $definitions[$key]->cast($definitions[$key]->default)]);
            }
        });
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
            default => $value === '' ? null : $value,
        };
    }
}
