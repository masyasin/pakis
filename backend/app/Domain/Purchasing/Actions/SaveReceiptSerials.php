<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Enums\SerialInputMethod;
use App\Domain\Inventory\Enums\SerialStatus;
use App\Domain\Inventory\Models\SerialNumber;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptLine;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Upload\SecureUpload;
use App\Support\Upload\StoredUpload;
use App\Support\Upload\UploadTarget;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Isi/ganti nomor seri satu baris penerimaan (BR-144, SEC-109): daftar nomor = himpunan lengkap baris
 * itu (scan satu-satu atau tempel daftar), dinormalisasi huruf besar tanpa spasi, unik dalam kiriman
 * dan terhadap `serial_numbers` produk yang sama (`SERIAL_DUPLICATE`; unik DB lapis kedua). Nomor yang
 * sudah ada di baris ini dipertahankan; yang hilang dari daftar dihapus; yang baru dibuat dengan
 * `input_method` kiriman — manual wajib foto label (disimpan sebelum transaksi, dihapus bila gagal).
 * Nomor seri dibuat dengan `stock_batch_id` NULL sampai GR diposting (PA-65). Mengubah nomor seri penerimaan
 * yang sudah `checked` mengembalikannya ke `draft` (wajib periksa ulang, SEC-104).
 */
final class SaveReceiptSerials
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SecureUpload $uploads,
    ) {}

    /**
     * @param  list<string>  $serials
     * @return array{total: int, added: int, removed: int, reset: bool}
     */
    public function handle(GoodsReceipt $receipt, GoodsReceiptLine $line, array $serials, SerialInputMethod $method, ?UploadedFile $photo, User $actor): array
    {
        if ($line->goods_receipt_id !== $receipt->id) {
            throw ValidationException::withMessages(['line' => 'Baris tidak termasuk penerimaan ini.']);
        }
        $line->loadMissing('product:id,sku,tracks_serial');
        if (! $line->product->tracks_serial) {
            throw ValidationException::withMessages(['serials' => "Produk {$line->product->sku} tidak melacak nomor seri."]);
        }

        $normalized = [];
        foreach ($serials as $raw) {
            $no = SerialNumber::normalize($raw);
            if ($no !== '') {
                $normalized[] = $no;
            }
        }
        $dupes = array_values(array_unique(array_diff_assoc($normalized, array_unique($normalized))));
        if ($dupes !== []) {
            throw new ApiError(ErrorCode::SERIAL_DUPLICATE, ['serials' => $dupes, 'form' => 'Nomor seri kembar dalam daftar: '.implode(', ', $dupes)]);
        }
        foreach ($normalized as $no) {
            if (mb_strlen($no) > 80) {
                throw ValidationException::withMessages(['serials' => 'Nomor seri maksimal 80 karakter.']);
            }
        }

        $stored = null;
        if ($method === SerialInputMethod::Manual) {
            if ($photo === null) {
                throw ValidationException::withMessages(['photo' => 'Input manual wajib menyertakan foto label nomor seri.']);
            }
            $stored = $this->uploads->store($photo, UploadTarget::privatePhoto('serial-labels', 'photo'));
        }

        try {
            return DB::transaction(fn () => $this->replace($receipt, $line, $normalized, $method, $stored, $actor));
        } catch (Throwable $e) {
            if ($stored !== null) {
                $this->uploads->delete($stored->disk, $stored->path);
            }
            if ($e instanceof QueryException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new ApiError(ErrorCode::SERIAL_DUPLICATE, ['form' => ErrorCode::SERIAL_DUPLICATE->message()]);
            }
            throw $e;
        }
    }

    /**
     * @param  list<string>  $serials
     * @return array{total: int, added: int, removed: int, reset: bool}
     */
    private function replace(GoodsReceipt $receipt, GoodsReceiptLine $line, array $serials, SerialInputMethod $method, ?StoredUpload $stored, User $actor): array
    {
        $locked = GoodsReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
        if (! $locked->status->isEditable()) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Nomor seri hanya bisa diubah sebelum penerimaan diposting.');
        }
        GoodsReceiptLine::query()->lockForUpdate()->findOrFail($line->id);

        $conflicts = SerialNumber::query()->where('product_id', $line->product_id)->whereIn('serial_no', $serials)
            ->where(fn ($q) => $q->whereNull('goods_receipt_line_id')->orWhere('goods_receipt_line_id', '!=', $line->id))
            ->pluck('serial_no')->all();
        if ($conflicts !== []) {
            throw new ApiError(ErrorCode::SERIAL_DUPLICATE, ['serials' => $conflicts, 'form' => 'Sudah tercatat untuk produk ini: '.implode(', ', $conflicts)]);
        }

        $existing = SerialNumber::query()->where('goods_receipt_line_id', $line->id)->pluck('id', 'serial_no');
        $removed = $existing->keys()->diff($serials);
        if ($removed->isNotEmpty()) {
            SerialNumber::query()->whereIn('id', $existing->only($removed->all())->values()->all())->delete();
        }
        $added = 0;
        foreach ($serials as $no) {
            if ($existing->has($no)) {
                continue;
            }
            (new SerialNumber)->forceFill([
                'product_id' => $line->product_id,
                'serial_no' => $no,
                'goods_receipt_line_id' => $line->id,
                'stock_batch_id' => null,
                'status' => SerialStatus::InStock,
                'input_method' => $method,
                'label_photo_path' => $method === SerialInputMethod::Manual ? $stored?->path : null,
            ])->save();
            $added++;
        }

        // SEC-104 dua orang: hasil periksa hanya berlaku untuk isi yang diperiksa. Bila nomor seri berubah
        // setelah diperiksa, penerimaan kembali draf agar pemosting tidak bisa mengubah isi lalu langsung
        // memposting tanpa pemeriksa (pola SaveGoodsReceipt).
        $reset = $locked->status === GoodsReceiptStatus::Checked && ($added > 0 || $removed->isNotEmpty());
        if ($reset) {
            $before = $locked->audited();
            $locked->forceFill(['status' => GoodsReceiptStatus::Draft, 'checked_by' => null, 'checked_at' => null, 'updated_by' => $actor->id])->save();
            $this->audit->record('goods_receipt.updated', $locked, $before, [...$locked->audited(), 'reason' => 'serials_changed_after_check']);
        }

        $this->audit->record('goods_receipt.serials', $locked, null, [
            'line_id' => $line->id, 'product_id' => $line->product_id, 'total' => count($serials), 'added' => $added, 'removed' => $removed->count(), 'method' => $method->value,
        ]);

        return ['total' => count($serials), 'added' => $added, 'removed' => $removed->count(), 'reset' => $reset];
    }
}
