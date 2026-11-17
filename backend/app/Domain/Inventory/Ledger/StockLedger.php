<?php

namespace App\Domain\Inventory\Ledger;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\BinZone;
use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Ledger\Exceptions\BatchNotAvailable;
use App\Domain\Inventory\Ledger\Exceptions\InsufficientStock;
use App\Domain\Inventory\Ledger\Exceptions\StockConflict;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\StockReservation;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;
use App\Support\Money\UnitCost;
use Brick\Math\BigDecimal;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use stdClass;

/**
 * Satu-satunya penulis saldo & mutasi stok (ADR-103, BR-101, BR-154, BR-155, SEC-102).
 *
 * Aturan pakai untuk task pemakai (T-078, T-080–T-086, Paket B/C):
 * - Semua method WAJIB dipanggil di dalam transaksi milik Action pemanggil (tanpa transaksi →
 *   LogicException), supaya mutasi, dokumen, `PeriodGuard`, dan outbox akuntansi jatuh/berhasil
 *   bersama. Bungkus transaksi Action dengan `StockLedger::transaction(fn () => ...)`: deadlock
 *   (SQLSTATE 40001/1213) diulang seluruhnya maks 3 kali (jeda acak 50–200 ms), lalu `StockConflict`
 *   → `409 STATE_CONFLICT`. Mengulang di tengah transaksi tidak sah (server sudah membatalkan seluruh
 *   transaksi), jadi retry hanya di transaksi terluar. Transaksi terluar itu berjalan READ COMMITTED
 *   (lihat `transaction()`); Action yang memakai `DB::transaction` biasa berisiko galat 1020 MariaDB
 *   saat ramai.
 * - Baris `stock_batches` dikunci `SELECT … FOR UPDATE` urut `id` naik. CHECK DB = lapis kedua.
 * - Setiap perubahan `qty_on_hand` menulis tepat satu mutasi per batch dengan `qty_after` = saldo baru.
 * - Qty selalu satuan dasar (ADR-102) dan selalu `Qty`/`UnitCost` (brick/math), tidak pernah float.
 */
final class StockLedger
{
    /** Retry setelah percobaan pertama (BR-101: "maks 3 kali"). */
    public const MAX_RETRIES = 3;

    /**
     * Tipe sumber yang boleh mereservasi batch `quarantine`/`expired` (PA-68, T-081): retur ke prinsipal
     * justru mengeluarkan barang karantina/ED, dan reservasinya melindungi qty dari dokumen lain
     * (pemusnahan, opname). Sumber lain tetap hanya batch `available`; `disposed` selalu ditolak.
     *
     * @var list<string>
     */
    public const RESERVE_ANY_STATUS_SOURCES = ['purchase_return'];

    public function __construct(private readonly CostCalculator $costs) {}

    /**
     * Transaksi Action yang menyentuh stok, dengan retry deadlock (BR-101). Di dalam transaksi lain
     * (mis. test ber-RefreshDatabase, atau Action yang dipanggil Action lain) callback dijalankan
     * sebagai savepoint tanpa retry; deadlock diteruskan agar transaksi terluar yang mengulang.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        if (DB::transactionLevel() > 0) {
            return DB::transaction($callback(...));
        }

        for ($retry = 0; ; $retry++) {
            try {
                // READ COMMITTED untuk transaksi berikutnya saja (tanpa SESSION). Di REPEATABLE READ,
                // MariaDB ≥ 11.6 (`innodb_snapshot_isolation=ON`) menolak `FOR UPDATE` atas baris yang
                // di-commit transaksi lain sesudah bacaan pertama (galat 1020), padahal Action wajar
                // membaca dokumen/batch dulu sebelum ledger mengunci. Kunci baris tetap menjamin BR-101.
                DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');

                return DB::transaction($callback(...));
            } catch (QueryException $e) {
                if (! self::isDeadlock($e)) {
                    throw $e;
                }
                if ($retry >= self::MAX_RETRIES) {
                    throw new StockConflict($e);
                }
                usleep(random_int(50_000, 200_000));
            }
        }
    }

    /**
     * Bentrok kunci yang sah diulang: SQLSTATE 40001 / 1213 (deadlock, BR-101) dan 1020 MariaDB
     * ("Record has changed since last read … try restarting transaction", snapshot isolation) bila
     * transaksi tetap berjalan di REPEATABLE READ.
     */
    public static function isDeadlock(QueryException $e): bool
    {
        return $e->getCode() === '40001' || in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1020], true);
    }

    /**
     * Penerimaan (receipt, saldo awal, retur jual) ke batch lokasi+produk+batch+rak; batch dibuat
     * bila belum ada. HPP dihitung ulang (BR-159) sebelum saldo naik. `unit_cost` mutasi = biaya terima.
     */
    public function receive(
        StockLocation $location,
        Product $product,
        BatchKey $key,
        Qty $qty,
        UnitCost $unitCost,
        Source $source,
        DateTimeInterface|string $businessDate,
        MovementType $type = MovementType::Receipt,
        ?string $note = null,
    ): LedgerResult {
        self::assertInTransaction();
        self::assertPositive($qty);
        if (! $type->recalculatesCost()) {
            throw new InvalidArgumentException("Jenis mutasi [{$type->value}] bukan penerimaan; pakai move()/adjust().");
        }
        if ($unitCost->isNegative()) {
            throw new InvalidArgumentException('Biaya terima tidak boleh negatif.');
        }
        $date = LedgerDate::normalize($businessDate);

        // Kunci product_costs lebih dulu (urutan kunci CostCalculator), baru batch tujuan.
        $this->costs->applyReceipt($product->id, $qty, $unitCost);

        $row = $this->lockOrCreateBatch($location, $product->id, $key, [
            'received_date' => $date,
            'receipt_unit_cost' => (string) $unitCost,
            'status' => $this->statusForBin($key->binId)->value,
        ]);

        $onHand = Qty::of((string) $row->qty_on_hand);
        $after = $onHand->plus($qty);
        $receiptCost = $row->receipt_unit_cost === null
            ? $unitCost
            // Biaya beli batch yang diterima ulang = rata-rata tertimbang (telusur retur prinsipal BR-159).
            : CostCalculator::average($onHand, UnitCost::of((string) $row->receipt_unit_cost), $qty, $unitCost);

        $this->writeBatch((int) $row->id, ['qty_on_hand' => (string) $after, 'receipt_unit_cost' => (string) $receiptCost]);
        $movement = $this->writeMovement($row, $type, $qty->toBigDecimal(), $after, $unitCost, $source, $date, $note);

        return $this->result([$movement]);
    }

    /**
     * Pengurangan stok satu batch (`$qty` wajib) atau beberapa batch (`list<StockLine>`, `$qty` null).
     * `unit_cost` = HPP rata-rata saat itu.
     *
     * @param  StockBatch|list<StockLine>  $target
     */
    public function issue(
        StockBatch|array $target,
        ?Qty $qty,
        Source $source,
        MovementType $type,
        DateTimeInterface|string $businessDate,
        ?string $note = null,
    ): LedgerResult {
        self::assertInTransaction();
        self::assertNoNegativeStock();
        if (! $type->isOutbound()) {
            throw new InvalidArgumentException("Jenis mutasi [{$type->value}] bukan pengurang stok.");
        }
        $date = LedgerDate::normalize($businessDate);

        $wanted = $this->normalizeLines($target, $qty);
        $rows = $this->lockBatches(array_keys($wanted));

        foreach ($wanted as $id => $q) {
            $this->assertCanTake($rows[$id], $q, $type);
        }

        $movements = [];
        foreach ($wanted as $id => $q) {
            $row = $rows[$id];
            $after = Qty::of((string) $row->qty_on_hand)->minus($q);
            $this->writeBatch($id, ['qty_on_hand' => (string) $after]);
            $movements[] = $this->writeMovement($row, $type, $q->toBigDecimal()->negated(), $after, $this->costs->current((int) $row->product_id), $source, $date, $note);
        }

        return $this->result($movements);
    }

    /**
     * Pindah qty ke lokasi stok lain (batch tanpa rak) atau ke rak (dalam lokasi yang sama maupun
     * lain): dua mutasi dalam satu panggilan (`typeOut` di asal, `typeIn` di tujuan). Batch tujuan
     * dibuat bila belum ada dengan nomor, ED, tanggal terima, dan biaya beli yang sama (BR-161).
     * Status batch tujuan baru: rak karantina → `quarantine` (BR-164); asal kedaluwarsa → `expired`;
     * selain itu `available` (lepas karantina setelah approval dilakukan pemanggil).
     */
    public function move(
        StockBatch $batch,
        StockLocation|StockBin $to,
        Qty $qty,
        Source $source,
        MovementType $typeOut,
        MovementType $typeIn,
        DateTimeInterface|string $businessDate,
        ?string $note = null,
    ): LedgerResult {
        self::assertInTransaction();
        self::assertNoNegativeStock();
        self::assertPositive($qty);
        if (! $typeOut->isOutbound() || ! $typeIn->isInbound()) {
            throw new InvalidArgumentException("Pasangan jenis mutasi [{$typeOut->value} → {$typeIn->value}] tidak sah.");
        }
        $date = LedgerDate::normalize($businessDate);

        [$toLocation, $toBinId] = $to instanceof StockBin
            ? [StockLocation::withTrashed()->findOrFail($to->stock_location_id), $to->id]
            : [$to, null];
        if ($toLocation->id === $batch->stock_location_id && $toBinId === $batch->stock_bin_id) {
            throw new InvalidArgumentException('Lokasi & rak tujuan sama dengan asal.');
        }

        $key = new BatchKey($batch->batch_no, $batch->expiry_date, $toBinId);
        $existingId = $this->findBatchId($toLocation->id, $batch->product_id, $key);

        if ($existingId !== null) {
            $rows = $this->lockBatches([$batch->id, $existingId]);
            $src = $rows[$batch->id];
            $dst = $rows[$existingId];
        } else {
            $src = $this->lockBatches([$batch->id])[$batch->id];
            $dst = $this->lockOrCreateBatch($toLocation, $batch->product_id, $key, [
                'received_date' => $src->received_date,
                'receipt_unit_cost' => $src->receipt_unit_cost,
                'status' => $this->statusForMove($toBinId, StockBatchStatus::from((string) $src->status))->value,
            ]);
        }

        $this->assertCanTake($src, $qty, $typeOut);

        $cost = $this->costs->current($batch->product_id);
        $srcAfter = Qty::of((string) $src->qty_on_hand)->minus($qty);
        $dstAfter = Qty::of((string) $dst->qty_on_hand)->plus($qty);

        $this->writeBatch((int) $src->id, ['qty_on_hand' => (string) $srcAfter]);
        $out = $this->writeMovement($src, $typeOut, $qty->toBigDecimal()->negated(), $srcAfter, $cost, $source, $date, $note);
        $this->writeBatch((int) $dst->id, ['qty_on_hand' => (string) $dstAfter]);
        $in = $this->writeMovement($dst, $typeIn, $qty->toBigDecimal(), $dstAfter, $cost, $source, $date, $note);

        return $this->result([$out, $in]);
    }

    /**
     * Reservasi (BR-155): menaikkan `qty_reserved`, `qty_on_hand` tetap, tanpa mutasi. Hanya batch
     * `available`, kecuali sumber di `RESERVE_ANY_STATUS_SOURCES` (juga `quarantine`/`expired`). Masa
     * berlaku bawaan `inventory.reservation_ttl_hours`.
     */
    public function reserve(StockBatch $batch, Qty $qty, Source $source, ?int $ttlHours = null): StockReservation
    {
        self::assertInTransaction();
        self::assertPositive($qty);
        $ttl = $ttlHours ?? (int) setting('inventory.reservation_ttl_hours');
        if ($ttl < 1) {
            throw new InvalidArgumentException('Masa berlaku reservasi minimal 1 jam.');
        }

        $row = $this->lockBatches([$batch->id])[$batch->id];
        $status = StockBatchStatus::from((string) $row->status);
        $anyStatus = in_array($source->type, self::RESERVE_ANY_STATUS_SOURCES, true);
        if ($status === StockBatchStatus::Disposed || ($status !== StockBatchStatus::Available && ! $anyStatus)) {
            throw new BatchNotAvailable((int) $row->id, $status);
        }
        $this->assertAvailable($row, $qty);

        $this->writeBatch($batch->id, ['qty_reserved' => (string) Qty::of((string) $row->qty_reserved)->plus($qty)]);
        $now = now();
        $id = DB::table('stock_reservations')->insertGetId([
            'stock_batch_id' => $batch->id,
            'qty' => (string) $qty,
            'source_type' => $source->type,
            'source_id' => $source->id,
            'source_line_id' => $source->lineId,
            'status' => ReservationStatus::Active->value,
            'expires_at' => $now->copy()->addHours($ttl),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return StockReservation::query()->findOrFail($id);
    }

    /**
     * Konsumsi reservasi aktif: `qty_reserved` dan `qty_on_hand` turun bersama dalam satu transaksi
     * (BR-155), satu mutasi `$type` bersumber dokumen reservasi. Reservasi yang sudah dikonsumsi/
     * dilepas (mis. oleh job kedaluwarsa) → `409 STATE_CONFLICT`.
     */
    public function consume(StockReservation $reservation, MovementType $type, DateTimeInterface|string $businessDate, ?string $note = null): LedgerResult
    {
        self::assertInTransaction();
        self::assertNoNegativeStock();
        if (! $type->isOutbound()) {
            throw new InvalidArgumentException("Jenis mutasi [{$type->value}] bukan pengurang stok.");
        }
        $date = LedgerDate::normalize($businessDate);

        $row = $this->lockBatches([$reservation->stock_batch_id])[$reservation->stock_batch_id];
        $res = $this->lockActiveReservation($reservation);
        if ($res === null) {
            throw new ApiError(ErrorCode::STATE_CONFLICT);
        }
        if ($type->requiresAvailableBatch() && $row->status !== StockBatchStatus::Available->value) {
            throw new BatchNotAvailable((int) $row->id, StockBatchStatus::from((string) $row->status));
        }

        $qty = Qty::of((string) $res->qty);
        $after = Qty::of((string) $row->qty_on_hand)->minus($qty);
        $this->writeBatch((int) $row->id, [
            'qty_on_hand' => (string) $after,
            'qty_reserved' => (string) Qty::of((string) $row->qty_reserved)->minus($qty),
        ]);
        $this->writeReservationStatus((int) $res->id, ReservationStatus::Consumed);

        $source = new Source((string) $res->source_type, (int) $res->source_id, $res->source_line_id === null ? null : (int) $res->source_line_id);
        $movement = $this->writeMovement($row, $type, $qty->toBigDecimal()->negated(), $after, $this->costs->current((int) $row->product_id), $source, $date, $note);

        return $this->result([$movement]);
    }

    /** Lepas reservasi aktif (`qty_reserved` turun). Idempoten: sudah tidak aktif → false. */
    public function release(StockReservation $reservation): bool
    {
        self::assertInTransaction();

        $row = $this->lockBatches([$reservation->stock_batch_id])[$reservation->stock_batch_id];
        $res = $this->lockActiveReservation($reservation);
        if ($res === null) {
            return false;
        }

        $this->writeBatch((int) $row->id, ['qty_reserved' => (string) Qty::of((string) $row->qty_reserved)->minus((string) $res->qty)]);
        $this->writeReservationStatus((int) $res->id, ReservationStatus::Released);

        return true;
    }

    /**
     * Penyesuaian bertanda (opname, selisih mutasi, dokumen Paket C): + → `adjustment_in`, − →
     * `adjustment_out`, `unit_cost` = HPP rata-rata (BR-162(6)(7)). `BatchKey` membuat batch baru
     * (batch ditemukan saat opname) dan wajib disertai `$location` + `$product`.
     *
     * `$reasonId` (referensi alasan, T-083/T-084) tidak divalidasi di sini (keputusan T-077) dan
     * tidak punya kolom di `stock_movements`: alasan dicatat di baris dokumen sumber.
     */
    public function adjust(
        StockBatch|BatchKey $target,
        Qty $delta,
        Source $source,
        ?int $reasonId,
        DateTimeInterface|string $businessDate,
        ?StockLocation $location = null,
        ?Product $product = null,
        ?string $note = null,
    ): LedgerResult {
        self::assertInTransaction();
        self::assertNoNegativeStock();
        if ($delta->isZero()) {
            throw new InvalidArgumentException('Qty penyesuaian tidak boleh nol.');
        }
        $date = LedgerDate::normalize($businessDate);

        if ($target instanceof BatchKey) {
            if ($location === null || $product === null) {
                throw new InvalidArgumentException('Penyesuaian dengan BatchKey wajib menyebut lokasi stok dan produk.');
            }
            $row = $this->lockOrCreateBatch($location, $product->id, $target, [
                'received_date' => $date,
                'receipt_unit_cost' => null,
                'status' => $this->statusForBin($target->binId)->value,
            ]);
        } else {
            $row = $this->lockBatches([$target->id])[$target->id];
        }

        $type = $delta->isPositive() ? MovementType::AdjustmentIn : MovementType::AdjustmentOut;
        if ($delta->isNegative()) {
            $this->assertAvailable($row, Qty::of($delta->toBigDecimal()->negated()));
        }

        $after = Qty::of((string) $row->qty_on_hand)->plus($delta);
        $this->writeBatch((int) $row->id, ['qty_on_hand' => (string) $after]);
        $movement = $this->writeMovement($row, $type, $delta->toBigDecimal(), $after, $this->costs->current((int) $row->product_id), $source, $date, $note);

        return $this->result([$movement]);
    }

    /**
     * Ubah status batch + catat `stock_batch_status_logs` (user NULL = job). Tanpa mutasi & tanpa
     * event akuntansi (15 §11.4). Meninggalkan `available` melepas semua reservasi aktifnya (BR-157),
     * sehingga reservasi hanya pernah ada di batch tersedia.
     *
     * Ditolak (LogicException, pemanggil wajib memeriksa lebih dulu): `disposed` final; `disposed`
     * hanya saat saldo 0 (mutasi `disposal` dulu, BR-163); batch di rak karantina tidak pernah
     * `available` (BR-164); batch `expired` hanya bisa `disposed` (BR-164).
     */
    public function setStatus(StockBatch $batch, StockBatchStatus $status, ?int $reasonId = null, ?User $user = null, ?string $note = null): StockBatch
    {
        self::assertInTransaction();

        $row = $this->lockBatches([$batch->id])[$batch->id];
        $from = StockBatchStatus::from((string) $row->status);
        if ($from === $status) {
            return StockBatch::query()->findOrFail($batch->id);
        }

        if ($from === StockBatchStatus::Disposed) {
            throw new LogicException('Batch yang sudah dimusnahkan tidak bisa berubah status.');
        }
        if ($from === StockBatchStatus::Expired && $status !== StockBatchStatus::Disposed) {
            throw new LogicException('Batch kedaluwarsa hanya bisa dimusnahkan atau diretur.');
        }
        if ($status === StockBatchStatus::Disposed && ! Qty::of((string) $row->qty_on_hand)->isZero()) {
            throw new LogicException('Batch hanya bisa berstatus dimusnahkan setelah saldonya 0 (mutasi disposal).');
        }
        if ($status === StockBatchStatus::Available && $this->statusForBin($row->stock_bin_id === null ? null : (int) $row->stock_bin_id) === StockBatchStatus::Quarantine) {
            throw new LogicException('Batch di rak karantina tidak bisa tersedia; pindahkan ke rak lain (BR-164).');
        }

        $changes = ['status' => $status->value];
        if ($from === StockBatchStatus::Available) {
            $active = DB::table('stock_reservations')->where('stock_batch_id', $batch->id)
                ->where('status', ReservationStatus::Active->value)->orderBy('id')->lockForUpdate()->get();
            $released = Qty::zero();
            foreach ($active as $res) {
                $this->writeReservationStatus((int) $res->id, ReservationStatus::Released);
                $released = $released->plus((string) $res->qty);
            }
            $changes['qty_reserved'] = (string) Qty::of((string) $row->qty_reserved)->minus($released);
        }
        if ($status === StockBatchStatus::Quarantine) {
            $changes['quarantine_reason_id'] = $reasonId;
        } elseif ($status === StockBatchStatus::Available) {
            $changes['quarantine_reason_id'] = null;
        }

        $this->writeBatch($batch->id, $changes);
        DB::table('stock_batch_status_logs')->insert([
            'stock_batch_id' => $batch->id,
            'from_status' => $from->value,
            'to_status' => $status->value,
            'reason_id' => $reasonId,
            'note' => $note,
            'user_id' => $user?->id,
            'created_at' => now(),
        ]);

        return StockBatch::query()->findOrFail($batch->id);
    }

    // ── internal ──────────────────────────────────────────────────────────────────────────────

    private static function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('StockLedger wajib dipanggil di dalam transaksi Action (StockLedger::transaction).');
        }
    }

    /** `inventory.negative_stock_allowed` terkunci `false` (15 §9); nilai lain = konfigurasi rusak. */
    private static function assertNoNegativeStock(): void
    {
        if (setting('inventory.negative_stock_allowed') !== false) {
            throw new LogicException('Stok minus tidak didukung (inventory.negative_stock_allowed harus false).');
        }
    }

    private static function assertPositive(Qty $qty): void
    {
        if (! $qty->isPositive()) {
            throw new InvalidArgumentException('Qty harus lebih dari 0.');
        }
    }

    private function assertCanTake(stdClass $row, Qty $qty, MovementType $type): void
    {
        $status = StockBatchStatus::from((string) $row->status);
        if ($status === StockBatchStatus::Disposed || ($type->requiresAvailableBatch() && $status !== StockBatchStatus::Available)) {
            throw new BatchNotAvailable((int) $row->id, $status);
        }
        $this->assertAvailable($row, $qty);
    }

    private function assertAvailable(stdClass $row, Qty $qty): void
    {
        $available = Qty::of((string) $row->qty_on_hand)->minus((string) $row->qty_reserved);
        if ($available->compareTo($qty) < 0) {
            throw new InsufficientStock((int) $row->id, $available, $qty);
        }
    }

    /**
     * Satukan target issue menjadi id batch => qty (batch yang sama dijumlah: satu mutasi per batch).
     *
     * @param  StockBatch|list<StockLine>  $target
     * @return array<int, Qty>
     */
    private function normalizeLines(StockBatch|array $target, ?Qty $qty): array
    {
        if ($target instanceof StockBatch) {
            if ($qty === null) {
                throw new InvalidArgumentException('Qty wajib untuk issue satu batch.');
            }
            $target = [new StockLine($target, $qty)];
        } elseif ($qty !== null) {
            throw new InvalidArgumentException('Qty per batch dibawa StockLine; argumen qty harus null.');
        }
        if ($target === []) {
            throw new InvalidArgumentException('Tidak ada batch yang dikurangi.');
        }

        $wanted = [];
        foreach ($target as $line) {
            self::assertPositive($line->qty);
            $id = $line->batch->id;
            $wanted[$id] = isset($wanted[$id]) ? $wanted[$id]->plus($line->qty) : $line->qty;
        }
        ksort($wanted);

        return $wanted;
    }

    /**
     * `SELECT … FOR UPDATE` urut id naik (BR-101: urutan kunci tetap mencegah deadlock antar dokumen).
     *
     * @param  list<int>  $ids
     * @return array<int, stdClass>
     */
    private function lockBatches(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);
        $rows = DB::table('stock_batches')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id')->all();
        if (count($rows) !== count($ids)) {
            throw new LogicException('Batch stok tidak ditemukan.');
        }

        return $rows;
    }

    private function findBatchId(int $locationId, int $productId, BatchKey $key): ?int
    {
        $id = DB::table('stock_batches')->where('stock_location_id', $locationId)->where('product_id', $productId)
            ->where('batch_no', $key->batchNo)->where('bin_key', $key->binId ?? 0)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Ambil & kunci batch kunci unik; buat bila belum ada (insert-or-lock). Penerimaan bersamaan ke
     * batch yang sama tidak membuat duplikat: INSERT IGNORE kedua menunggu yang pertama, lalu
     * mendapati baris yang sudah ada. Cek awal tanpa kunci agar batch yang sudah ada dikunci per
     * baris (bukan gap lock pada indeks unik, yang saling bertabrakan saat dua transaksi insert).
     *
     * @param  array{received_date: ?string, receipt_unit_cost: ?string, status: string}  $new
     */
    private function lockOrCreateBatch(StockLocation $location, int $productId, BatchKey $key, array $new): stdClass
    {
        $this->assertBinFits($location, $key->binId);

        $id = $this->findBatchId($location->id, $productId, $key);
        if ($id === null) {
            $now = now();
            DB::table('stock_batches')->insertOrIgnore([
                'stock_location_id' => $location->id,
                'stock_bin_id' => $key->binId,
                'product_id' => $productId,
                'batch_no' => $key->batchNo,
                'expiry_date' => $key->expiryDate,
                'received_date' => $new['received_date'],
                'receipt_unit_cost' => $new['receipt_unit_cost'],
                'qty_on_hand' => '0.000',
                'qty_reserved' => '0.000',
                'status' => $new['status'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = $this->findBatchIdLocking($location->id, $productId, $key);
        }

        $row = $this->lockBatches([$id])[$id];
        if ($key->expiryDate !== null && $row->expiry_date !== null && (string) $row->expiry_date !== $key->expiryDate) {
            throw new LogicException("ED batch {$key->batchNo} berbeda dengan batch yang sudah ada.");
        }

        return $row;
    }

    /** Setelah INSERT IGNORE: baca terkunci agar baris yang baru di-commit transaksi lain terlihat. */
    private function findBatchIdLocking(int $locationId, int $productId, BatchKey $key): int
    {
        $id = DB::table('stock_batches')->where('stock_location_id', $locationId)->where('product_id', $productId)
            ->where('batch_no', $key->batchNo)->where('bin_key', $key->binId ?? 0)->lockForUpdate()->value('id');
        if ($id === null) {
            throw new LogicException('Batch stok gagal dibuat (lokasi/produk/rak tidak sah).');
        }

        return (int) $id;
    }

    /** Rak harus milik lokasi stok itu; kendaraan & TRANSIT selalu tanpa rak (15 §4.8). */
    private function assertBinFits(StockLocation $location, ?int $binId): void
    {
        if ($binId === null) {
            return;
        }
        if (! $location->type->hasBins() || ! StockBin::query()->whereKey($binId)->where('stock_location_id', $location->id)->exists()) {
            throw new InvalidArgumentException('Rak tidak termasuk lokasi stok tujuan.');
        }
    }

    /** Batch di rak zona karantina selalu `quarantine` (BR-164). */
    private function statusForBin(?int $binId): StockBatchStatus
    {
        if ($binId !== null && StockBin::query()->find($binId)?->zone === BinZone::Quarantine) {
            return StockBatchStatus::Quarantine;
        }

        return StockBatchStatus::Available;
    }

    private function statusForMove(?int $toBinId, StockBatchStatus $from): StockBatchStatus
    {
        if ($this->statusForBin($toBinId) === StockBatchStatus::Quarantine) {
            return StockBatchStatus::Quarantine;
        }

        return $from === StockBatchStatus::Expired ? StockBatchStatus::Expired : StockBatchStatus::Available;
    }

    private function lockActiveReservation(StockReservation $reservation): ?stdClass
    {
        $res = DB::table('stock_reservations')->where('id', $reservation->id)->lockForUpdate()->first();
        if ($res === null) {
            throw new LogicException('Reservasi tidak ditemukan.');
        }

        return $res->status === ReservationStatus::Active->value ? $res : null;
    }

    private function writeReservationStatus(int $id, ReservationStatus $status): void
    {
        DB::table('stock_reservations')->where('id', $id)->update(['status' => $status->value, 'updated_at' => now()]);
    }

    /** @param  array<string, string|int|null>  $values */
    private function writeBatch(int $id, array $values): void
    {
        DB::table('stock_batches')->where('id', $id)->update([...$values, 'updated_at' => now()]);
    }

    private function writeMovement(
        stdClass $batch,
        MovementType $type,
        BigDecimal $qty,
        Qty $after,
        UnitCost $unitCost,
        Source $source,
        string $date,
        ?string $note,
    ): StockMovement {
        $attributes = [
            'stock_batch_id' => (int) $batch->id,
            'product_id' => (int) $batch->product_id,
            'stock_location_id' => (int) $batch->stock_location_id,
            'movement_type' => $type->value,
            'qty' => (string) Qty::of($qty),
            'qty_after' => (string) $after,
            'unit_cost' => (string) $unitCost,
            'source_type' => $source->type,
            'source_id' => $source->id,
            'source_line_id' => $source->lineId,
            'business_date' => $date,
            'moved_at' => now()->format('Y-m-d H:i:s.v'),
            'user_id' => self::actorId(),
            'note' => $note === null ? null : mb_substr($note, 0, 255),
        ];
        $attributes['id'] = DB::table('stock_movements')->insertGetId($attributes);

        return (new StockMovement)->newFromBuilder($attributes);
    }

    private static function actorId(): ?int
    {
        $id = Auth::id();

        return $id === null ? null : (int) $id;
    }

    /** @param  list<StockMovement>  $movements */
    private function result(array $movements): LedgerResult
    {
        $ids = array_values(array_unique(array_map(fn (StockMovement $m) => $m->stock_batch_id, $movements)));
        $batches = StockBatch::query()->whereIn('id', $ids)->get()->keyBy('id')->all();

        return new LedgerResult($movements, $batches);
    }
}
