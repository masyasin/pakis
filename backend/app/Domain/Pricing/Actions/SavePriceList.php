<?php

namespace App\Domain\Pricing\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Pricing\Enums\PriceListKind;
use App\Domain\Pricing\Models\PriceList;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tambah/ubah daftar harga (FR-A19). `kind` dan targetnya (area/toko) tetap setelah dibuat, karena
 * resolusi harga & kios yang menunjuk list ini bergantung padanya. Hanya satu list `default`
 * (UNIQUE generated `default_key`; pelanggaran diterjemahkan ke galat field).
 */
final class SavePriceList
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data  sudah divalidasi PriceListRequest */
    public function handle(?PriceList $list, array $data, ?int $userId): PriceList
    {
        try {
            return DB::transaction(function () use ($list, $data, $userId) {
                if ($list === null) {
                    $kind = PriceListKind::from((string) $data['kind']);
                    $saved = new PriceList;
                    $saved->forceFill([
                        'code' => $data['code'], 'name' => $data['name'], 'kind' => $kind,
                        'sales_area_id' => $kind === PriceListKind::Area ? $data['sales_area_id'] : null,
                        'stock_location_id' => $kind === PriceListKind::Store ? $data['stock_location_id'] : null,
                        'description' => $data['description'] ?? null, 'is_active' => (bool) $data['is_active'],
                        'created_by' => $userId, 'updated_by' => $userId,
                    ])->save();
                    $this->audit->record('price_list.created', $saved, null, $saved->audited());

                    return $saved;
                }

                $saved = PriceList::query()->lockForUpdate()->findOrFail($list->id);
                $before = $saved->audited();
                $saved->forceFill([
                    'code' => $data['code'], 'name' => $data['name'],
                    'description' => $data['description'] ?? null, 'is_active' => (bool) $data['is_active'],
                ]);
                if ($saved->isDirty()) {
                    $saved->updated_by = $userId;
                    $saved->save();
                    $this->audit->record('price_list.updated', $saved, $before, $saved->audited());
                }

                return $saved;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(
                str_contains($e->getMessage(), 'price_lists_one_default')
                    ? ['kind' => 'Daftar harga bawaan sudah ada. Hanya boleh satu.']
                    : ['code' => 'Kode ini sudah dipakai daftar harga lain.'],
            );
        }
    }
}
