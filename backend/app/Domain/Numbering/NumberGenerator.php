<?php

namespace App\Domain\Numbering;

use App\Domain\Numbering\Models\DocumentSequence;
use App\Support\Time\DisplayTime;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * Satu-satunya pembuat nomor dokumen (ADR-009, BR-025, SEC-030). Nomor tidak pernah dibuat di klien.
 *
 * WAJIB dipanggil di dalam transaksi pemanggil, yang juga menyimpan dokumennya: lock baris counter
 * (`SELECT … FOR UPDATE`) baru dilepas saat transaksi selesai, sehingga permintaan bersamaan
 * antre dan nomor yang gagal disimpan ikut di-rollback (tidak ada nomor "hilang").
 *
 *     DB::transaction(function () use ($data) {
 *         $data['employee_number'] = $numbers->next('employee', isTaken: fn ($n) => Employee::where('employee_number', $n)->exists());
 *         Employee::create($data);
 *     });
 */
final class NumberGenerator
{
    /** Batas lompat bila nomor sudah terpakai (mis. setelah pola diubah). */
    public const MAX_SKIPS = 50;

    /**
     * @param  (Closure(string): bool)|null  $isTaken  apakah nomor sudah dipakai dokumen lain
     * @param  CarbonInterface|null  $at  waktu dokumen (default sekarang); periode memakai tanggal LOKAL
     */
    public function next(string $code, ?string $locationCode = null, ?Closure $isTaken = null, ?CarbonInterface $at = null): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('NumberGenerator::next() wajib dipanggil di dalam transaksi pemanggil.');
        }

        // Gerbang: kunci baris induk dulu. Tanpa ini, INSERT IGNORE pada counter yang sudah ada
        // mengambil shared lock di index unik, lalu FOR UPDATE di beberapa transaksi saling tunggu
        // → deadlock (terbukti oleh tests/Concurrency dengan 50 proses). Baris induk selalu ada,
        // jadi semua permintaan untuk kode yang sama antre rapi di sini.
        $sequence = DocumentSequence::query()->where('code', $code)->lockForUpdate()->first()
            ?? throw new LogicException("Penomoran [{$code}] belum terdaftar (SequenceSeeder).");

        $date = DisplayTime::toLocal($at ?? now());
        $scope = PeriodKey::for($sequence->reset_period, $date, PatternParser::usesLocation($sequence->pattern), $locationCode);

        // Baris counter dibuat bila belum ada (sudah aman: hanya satu transaksi lewat gerbang).
        DB::table('document_sequence_counters')->insertOrIgnore([
            'document_sequence_id' => $sequence->id,
            'scope_key' => $scope,
            'last_number' => 0,
            'updated_at' => now(),
        ]);

        $counter = DB::table('document_sequence_counters')
            ->where('document_sequence_id', $sequence->id)
            ->where('scope_key', $scope)
            ->lockForUpdate()
            ->first();

        $last = (int) $counter->last_number;

        for ($attempt = 0; $attempt <= self::MAX_SKIPS; $attempt++) {
            $last++;
            $number = PatternParser::format($sequence->pattern, $last, $date, $locationCode);

            if (strlen($number) > PatternParser::MAX_LENGTH) {
                throw new RuntimeException("Nomor [{$number}] melebihi ".PatternParser::MAX_LENGTH." karakter; periksa pola penomoran [{$code}].");
            }

            if ($isTaken === null || ! $isTaken($number)) {
                DB::table('document_sequence_counters')
                    ->where('id', $counter->id)
                    ->update(['last_number' => $last, 'updated_at' => now()]);

                return $number;
            }
        }

        throw new RuntimeException("Penomoran [{$code}] menemukan ".self::MAX_SKIPS.' nomor berturut-turut sudah terpakai; periksa pola penomoran.');
    }

    /**
     * Nomor-nomor berikutnya TANPA menaikkan counter (untuk pratinjau di layar).
     *
     * @return list<string>
     */
    public function preview(DocumentSequence $sequence, ?string $pattern = null, ?Enums\ResetPeriod $reset = null, int $count = 3, ?string $locationCode = null): array
    {
        $pattern ??= $sequence->pattern;
        $reset ??= $sequence->reset_period;
        $date = DisplayTime::toLocal(now());
        $usesLocation = PatternParser::usesLocation($pattern);
        $locationCode = $usesLocation ? ($locationCode ?? 'LOK') : null;

        $last = (int) DB::table('document_sequence_counters')
            ->where('document_sequence_id', $sequence->id)
            ->where('scope_key', PeriodKey::for($reset, $date, $usesLocation, $locationCode))
            ->value('last_number');

        return array_map(
            fn (int $i) => PatternParser::format($pattern, $last + $i, $date, $locationCode),
            range(1, $count),
        );
    }

    /**
     * Nomor terakhir pada periode berjalan (untuk tampilan daftar).
     */
    public function lastNumber(DocumentSequence $sequence): ?int
    {
        if (PatternParser::usesLocation($sequence->pattern)) {
            return null; // berbeda per lokasi
        }

        $value = DB::table('document_sequence_counters')
            ->where('document_sequence_id', $sequence->id)
            ->where('scope_key', PeriodKey::for($sequence->reset_period, DisplayTime::toLocal(now()), false))
            ->value('last_number');

        return $value === null ? null : (int) $value;
    }
}
