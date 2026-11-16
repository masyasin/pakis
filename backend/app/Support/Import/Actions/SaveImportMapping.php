<?php

namespace App\Support\Import\Actions;

use App\Models\User;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportMappingProfile;
use App\Support\Import\ImportRegistry;
use App\Support\Import\ImportRow;
use App\Support\Import\ImportStatus;
use App\Support\Import\ImportType;
use App\Support\Import\MappingSuggester;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Langkah 2 impor (BR-054): simpan pemetaan kolom → field, opsional sebagai profil, lalu mulai
 * validasi. Bisa diulang selama batch belum diproses (06 §7.4); hasil validasi lama dibuang.
 */
final class SaveImportMapping
{
    public function __construct(private readonly ValidateImportBatch $validate) {}

    /**
     * @param  array<int, string|null>  $columns  indeks kolom berkas → field (null = abaikan)
     */
    public function handle(User $actor, ImportBatch $batch, array $columns, ?string $profileName): ImportBatch
    {
        $batch = DB::transaction(function () use ($actor, $batch, $columns, $profileName) {
            $fail = fn (string $key, string $message) => throw ValidationException::withMessages([$key => $message]);

            $locked = ImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if (! $locked->status->canRemap()) {
                $fail('columns', 'Impor ini sudah '.mb_strtolower($locked->status->label()).'; pemetaannya tidak bisa diubah.');
            }

            $type = ImportRegistry::forBatch($locked);
            $fields = $type->fields();
            $headers = $locked->headers();
            $mapping = [];
            foreach ($columns as $index => $field) {
                if ($field === null || $field === '' || ! array_key_exists($index, $headers)) {
                    continue;
                }
                if (! $fields->has($field)) {
                    $fail('columns', 'Field tujuan tidak dikenal.');
                }
                if (in_array($field, $mapping, true)) {
                    $fail('columns', '"'.$fields->label($field).'" dipilih untuk lebih dari satu kolom.');
                }
                $mapping[$index] = $field;
            }

            $missing = array_diff($fields->required(), $mapping);
            if ($missing !== []) {
                $fail('columns', 'Field wajib belum dipetakan: '.implode(', ', array_map($fields->label(...), $missing)).'.');
            }

            $this->rememberProfile($actor, $type, $headers, $mapping, $profileName);

            $data = $locked->mapping ?? [];
            unset($data['failure']);
            $data['columns'] = array_map('strval', $mapping);

            ImportRow::query()->where('import_batch_id', $locked->id)->delete();
            $locked->forceFill([
                'mapping' => $data,
                'status' => ImportStatus::Mapped,
                'valid_rows' => 0,
                'error_rows' => 0,
                'updated_by' => $actor->id,
            ])->save();

            return $locked;
        });

        $this->validate->start($batch, $actor);

        return $batch->refresh();
    }

    /**
     * "Simpan sebagai profil": nama yang sudah ada ditimpa (memperbarui profil itu). Tanpa nama,
     * profil yang header-nya sama (yang tadi disarankan) ditandai baru dipakai.
     *
     * @param  list<string>  $headers
     * @param  array<int, string>  $mapping
     */
    private function rememberProfile(User $actor, ImportType $type, array $headers, array $mapping, ?string $profileName): void
    {
        $signature = MappingSuggester::signature($headers);

        if ($profileName === null) {
            ImportMappingProfile::query()
                ->where('type', $type->key())
                ->where('header_signature', $signature)
                ->latest('updated_at')
                ->first()
                ?->touch();

            return;
        }

        $byHeader = [];
        foreach ($mapping as $index => $field) {
            $header = $headers[$index];
            if ($header !== '') {
                $byHeader[$header] = $field;
            }
        }

        $profile = ImportMappingProfile::query()
            ->where('type', $type->key())
            ->where('name', $profileName)
            ->lockForUpdate()
            ->first() ?? (new ImportMappingProfile)->forceFill([
                'type' => $type->key(),
                'name' => $profileName,
                'created_by' => $actor->id,
            ]);

        $profile->forceFill([
            'header_signature' => $signature,
            'mapping' => $byHeader,
            'updated_by' => $actor->id,
        ])->save();
    }
}
