<?php

namespace App\Domain\Accounting\Outbox;

use App\Domain\Accounting\Outbox\Models\AccountingEvent;
use App\Domain\Accounting\Period\BusinessDate;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Pintu tunggal penulisan outbox akuntansi (ADR-104, 15 §4.8 & §11.4, 14 §7).
 *
 * Dipanggil Action pemancar DI DALAM transaksinya sendiri, setelah `PeriodGuard::assertOpen()`
 * dengan tanggal bisnis yang sama (PeriodGuardArchTest), sehingga dokumen, mutasi stok, dan event
 * selalu tertulis atau batal bersama.
 *
 * Payload divalidasi ketat karena event tidak pernah diperbaiki (14 §7 aturan 2) dan Paket F
 * mem-backfill jurnal darinya:
 * - kunci atas hanya `lines` (wajib, list), `refs` (objek), `memo` (string);
 * - tiap baris: `role` dari `EventCode::roles()`, tepat satu `debit` atau `credit` berupa string
 *   desimal ≥ 0 berskala 2 (DECIMAL(18,2)), + kualifier `EventCode::requiredLineKeys()` berupa ID positif;
 * - `refs` memuat `EventCode::requiredRefs()` berupa ID positif;
 * - tidak ada float di mana pun dalam payload (ADR-101).
 * Keseimbangan Σ debit = Σ kredit sengaja belum divalidasi di sini (validator katalog T-096).
 */
final class AccountingOutbox
{
    /** Nilai rupiah baris jurnal: DECIMAL(18,2), tanpa tanda (arah lewat debit/credit). */
    public const AMOUNT_PATTERN = '/^\d{1,16}\.\d{2}$/';

    private const TOP_LEVEL_KEYS = ['lines', 'refs', 'memo'];

    /**
     * @param  DateTimeInterface|string  $businessDate  tanggal bisnis lokal dokumen (`Y-m-d`; bagian jam diabaikan)
     * @param  array<string, mixed>  $payload
     * @param  int  $revision  1 untuk event asal; event pembalik/koreksi memakai revisi berikutnya
     *
     * @throws LogicException bila tidak ada transaksi aktif
     * @throws InvalidArgumentException bila payload melanggar kontrak (galat pemrograman, bukan input pengguna)
     * @throws ApiError STATE_CONFLICT bila event dengan kode, sumber, dan revisi yang sama sudah ada (posting ganda)
     */
    public static function record(EventCode $code, EventSource $source, DateTimeInterface|string $businessDate, array $payload, int $revision = 1): AccountingEvent
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('AccountingOutbox::record wajib dipanggil di dalam transaksi Action pemanggil.');
        }
        if ($revision < 1 || $revision > 65535) {
            throw new InvalidArgumentException('Revisi event harus 1–65535.');
        }

        $date = BusinessDate::normalize($businessDate);
        self::assertPayload($code, $payload);

        try {
            return AccountingEvent::query()->create([
                'event_code' => $code->value,
                'business_date' => $date,
                'source_type' => $source->type,
                'source_id' => $source->id,
                'revision' => $revision,
                'payload' => $payload,
                'status' => EventStatus::Pending,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // UNIQUE aev_unique: dokumen yang sama dipancarkan dua kali (mis. dua posting bersamaan).
            throw new ApiError(ErrorCode::STATE_CONFLICT, previous: $e);
        }
    }

    /**
     * Revisi berikutnya untuk kode + dokumen (1 bila belum ada). Untuk dokumen yang sah memancarkan
     * event yang sama lebih dari sekali, mis. penerimaan tambahan mutasi setelah hitung ulang (T-082,
     * PA-66). Di sini, bukan di pemanggil, karena hanya domain Accounting yang membaca
     * `accounting_events` (ADR-104). Pemanggil wajib sudah mengunci dokumennya agar dua transaksi
     * tidak mendapat revisi sama (bila terjadi, UNIQUE → STATE_CONFLICT di record()).
     */
    public static function nextRevision(EventCode $code, EventSource $source): int
    {
        $max = AccountingEvent::query()->where('event_code', $code->value)
            ->where('source_type', $source->type)->where('source_id', $source->id)->max('revision');

        return ((int) $max) + 1;
    }

    /**
     * Validasi kontrak payload tanpa menulis apa pun (dipakai record() dan test kontrak per event).
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidArgumentException
     */
    public static function assertPayload(EventCode $code, array $payload): void
    {
        $where = "Payload {$code->value}";

        self::assertNoFloat($payload, $where);

        $unknown = array_diff(array_keys($payload), self::TOP_LEVEL_KEYS);
        if ($unknown !== []) {
            throw new InvalidArgumentException("{$where}: kunci tidak dikenal [".implode(', ', $unknown).'].');
        }
        if (! array_key_exists('lines', $payload) || ! is_array($payload['lines']) || ! array_is_list($payload['lines'])) {
            throw new InvalidArgumentException("{$where}: `lines` wajib berupa daftar.");
        }
        if (array_key_exists('memo', $payload) && ! is_string($payload['memo'])) {
            throw new InvalidArgumentException("{$where}: `memo` harus string.");
        }

        $refs = $payload['refs'] ?? [];
        if (! is_array($refs) || ($refs !== [] && array_is_list($refs))) {
            throw new InvalidArgumentException("{$where}: `refs` harus objek.");
        }
        foreach ($code->requiredRefs() as $key) {
            if (! self::isId($refs[$key] ?? null)) {
                throw new InvalidArgumentException("{$where}: `refs.{$key}` wajib berupa ID positif.");
            }
        }

        $lines = $payload['lines'];
        if (! $code->journaled()) {
            if ($lines !== []) {
                throw new InvalidArgumentException("{$where}: event tanpa jurnal wajib `lines: []`.");
            }

            return;
        }
        if ($lines === []) {
            throw new InvalidArgumentException("{$where}: event berjurnal wajib punya baris.");
        }

        foreach ($lines as $i => $line) {
            self::assertLine($code, $line, "{$where} lines[{$i}]");
        }
    }

    private static function assertLine(EventCode $code, mixed $line, string $where): void
    {
        if (! is_array($line) || array_is_list($line)) {
            throw new InvalidArgumentException("{$where}: baris harus objek.");
        }

        $role = $line['role'] ?? null;
        if (! is_string($role) || ! in_array($role, $code->roles(), true)) {
            $role = is_scalar($role) ? (string) $role : gettype($role);
            throw new InvalidArgumentException("{$where}: peran [{$role}] tidak dikenal untuk event ini (boleh: ".implode(', ', $code->roles()).').');
        }

        $sides = array_values(array_intersect(['debit', 'credit'], array_keys($line)));
        if (count($sides) !== 1) {
            throw new InvalidArgumentException("{$where}: wajib tepat satu dari `debit` atau `credit`.");
        }
        $amount = $line[$sides[0]];
        if (! is_string($amount) || preg_match(self::AMOUNT_PATTERN, $amount) !== 1) {
            throw new InvalidArgumentException("{$where}: `{$sides[0]}` wajib string desimal ≥ 0 dengan 2 digit sen (mis. \"1250000.00\").");
        }

        foreach ($code->requiredLineKeys() as $key) {
            if (! self::isId($line[$key] ?? null)) {
                throw new InvalidArgumentException("{$where}: `{$key}` wajib berupa ID positif.");
            }
        }
    }

    /** Float (termasuk di dalam `refs`) bisa membulatkan nilai saat backfill: selalu ditolak. */
    private static function assertNoFloat(mixed $value, string $where, string $path = ''): void
    {
        if (is_float($value)) {
            throw new InvalidArgumentException("{$where}: nilai float di `{$path}` tidak boleh; pakai string desimal.");
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                self::assertNoFloat($item, $where, $path === '' ? (string) $key : "{$path}.{$key}");
            }
        }
    }

    private static function isId(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }
}
