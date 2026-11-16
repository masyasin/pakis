<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Numbering\NumberGenerator;
use App\Domain\Purchasing\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * Tambah/ubah supplier (FR-A11, SEC-108). Kode dari `NumberGenerator('supplier')` di dalam transaksi
 * (`SUP-0001`). Rekening:
 * - `bank_account_no` kosong = tidak diubah (form tidak pernah menerima nomor lama kembali);
 * - `clear_bank_account` = hapus rekening;
 * - audit hanya mencatat penanda `rekening: diubah/dihapus`, tidak pernah nomornya.
 * Produk dipasok disinkron: baris yang tidak dikirim dihapus, `last_price` yang sudah tercatat dari
 * penerimaan barang (T-080) tetap dipertahankan.
 */
final class SaveSupplier
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NumberGenerator $numbers,
    ) {}

    /**
     * @param  array<string, mixed>  $data  sudah divalidasi SupplierRequest
     * @param  list<array{product_id: int, supplier_sku: string|null, lead_time_days: int|null, is_preferred: bool}>|null  $products  null = tidak diubah
     */
    public function handle(?Supplier $supplier, array $data, ?array $products, ?int $userId): Supplier
    {
        return DB::transaction(function () use ($supplier, $data, $products, $userId) {
            $account = trim((string) ($data['bank_account_no'] ?? ''));
            $clear = (bool) ($data['clear_bank_account'] ?? false);
            $values = array_intersect_key($data, array_flip(Supplier::AUDITED));
            unset($values['code']);

            if ($supplier === null) {
                $saved = new Supplier;
                $saved->forceFill([
                    ...$values,
                    'code' => $this->numbers->next('supplier', isTaken: fn (string $n) => Supplier::withTrashed()->where('code', $n)->exists()),
                    'bank_account_no_enc' => $account !== '' ? $account : null,
                    'created_by' => $userId, 'updated_by' => $userId,
                ])->save();
                $this->syncProducts($saved, $products ?? []);
                $this->audit->record('supplier.created', $saved, null, [
                    ...$saved->audited(), 'bank_account' => $account !== '' ? 'diisi' : null,
                ]);

                return $saved;
            }

            $saved = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            $before = $saved->audited();
            $saved->forceFill($values);
            $accountChange = null;
            if ($clear && $saved->hasBankAccount()) {
                $saved->bank_account_no_enc = null;
                $accountChange = 'dihapus';
            } elseif ($account !== '' && $account !== (string) $saved->bank_account_no_enc) {
                $saved->bank_account_no_enc = $account;
                $accountChange = 'diubah';
            }

            $productsChanged = $products !== null && $this->syncProducts($saved, $products);
            if ($saved->isDirty() || $productsChanged) {
                $saved->updated_by = $userId;
                $saved->save();
                $this->audit->record('supplier.updated', $saved, $before, [
                    ...$saved->audited(),
                    'bank_account' => $accountChange,
                    'products' => $productsChanged ? 'diubah' : null,
                ]);
            }

            return $saved;
        });
    }

    /**
     * @param  list<array{product_id: int, supplier_sku: string|null, lead_time_days: int|null, is_preferred: bool}>  $rows
     * @return bool ada perubahan
     */
    private function syncProducts(Supplier $supplier, array $rows): bool
    {
        $table = DB::table('supplier_products');
        $existing = $table->clone()->where('supplier_id', $supplier->id)->get()->keyBy('product_id');
        $wanted = collect($rows)->keyBy('product_id');
        $changed = false;

        $removed = $existing->keys()->diff($wanted->keys())->values()->all();
        if ($removed !== []) {
            $table->clone()->where('supplier_id', $supplier->id)->whereIn('product_id', $removed)->delete();
            $changed = true;
        }

        foreach ($wanted as $productId => $row) {
            $values = [
                'supplier_sku' => $row['supplier_sku'],
                'lead_time_days' => $row['lead_time_days'],
                'is_preferred' => $row['is_preferred'],
            ];
            $old = $existing->get($productId);
            if ($old === null) {
                // last_price kosong sampai penerimaan barang pertama (T-080).
                $table->clone()->insert(['supplier_id' => $supplier->id, 'product_id' => $productId, ...$values, 'updated_at' => now()]);
                $changed = true;
            } elseif ($old->supplier_sku !== $values['supplier_sku'] || self::intOrNull($old->lead_time_days) !== $values['lead_time_days'] || (bool) $old->is_preferred !== $values['is_preferred']) {
                $table->clone()->where('supplier_id', $supplier->id)->where('product_id', $productId)->update([...$values, 'updated_at' => now()]);
                $changed = true;
            }
        }

        return $changed;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
