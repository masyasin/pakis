<?php

namespace App\Domain\Numbering\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Numbering\Enums\ResetPeriod;
use App\Domain\Numbering\Models\DocumentSequence;
use App\Domain\Numbering\PatternParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Ubah pola & periode reset satu penomoran (BR-025, audit `sequence.updated`).
 *
 * Nomor yang sudah terbit tidak berubah. Counter lama tetap tersimpan per scope; bila pola baru
 * menghasilkan nomor yang sudah dipakai, NumberGenerator melompatinya (isTaken).
 */
final class UpdateSequence
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(DocumentSequence $sequence, string $pattern, ResetPeriod $reset, ?int $userId): DocumentSequence
    {
        $pattern = trim($pattern);

        $errors = self::patternErrors($pattern, $reset);
        if ($errors !== []) {
            throw ValidationException::withMessages(['pattern' => $errors]);
        }

        return DB::transaction(function () use ($sequence, $pattern, $reset, $userId) {
            // Kunci baris yang sama dengan NumberGenerator: perubahan pola tidak menyela nomor yang
            // sedang dibuat di transaksi lain.
            $locked = DocumentSequence::query()->lockForUpdate()->findOrFail($sequence->id);
            $before = ['pattern' => $locked->pattern, 'reset_period' => $locked->reset_period->value];

            $locked->fill(['pattern' => $pattern, 'reset_period' => $reset, 'updated_by' => $userId])->save();

            $this->audit->record('sequence.updated', $locked, $before, [
                'pattern' => $locked->pattern,
                'reset_period' => $locked->reset_period->value,
            ]);

            return $locked;
        });
    }

    /**
     * Galat pola untuk form & pratinjau. `{LOC}` dihitung sepanjang kode lokasi terpanjang yang ada.
     *
     * @return list<string>
     */
    public static function patternErrors(string $pattern, ResetPeriod $reset): array
    {
        return PatternParser::errors(trim($pattern), $reset, self::longestLocationCode());
    }

    private static function longestLocationCode(): int
    {
        // Tabel lokasi baru ada di T-021; sebelum itu `{LOC}` dihitung 0 karakter.
        if (! Schema::hasTable('locations')) {
            return 0;
        }

        return (int) DB::table('locations')->max(DB::raw('CHAR_LENGTH(code)'));
    }
}
