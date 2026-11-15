<?php

namespace App\Domain\Attendance;

use App\Domain\Attendance\Models\Shift;

/**
 * Jadwal efektif satu pegawai pada satu tanggal lokal (BR-061/BR-062), hasil ScheduleResolver.
 *
 * `kind`:
 * - `shift`       — wajib masuk shift `$shift` (dari pola atau pengecualian; termasuk masuk di hari libur);
 * - `holiday`     — hari libur resmi yang berlaku di lokasi penugasan utama pada tanggal itu;
 * - `off`         — libur pegawai (hari kosong di pola, atau pengecualian "libur");
 * - `unscheduled` — belum ada pola yang berlaku dan tidak ada pengecualian.
 *
 * `source` menjelaskan asal keputusan (`override` | `pattern` | `holiday` | `none`) untuk tampilan
 * dan audit status harian (T-037). String biasa, bukan enum: nilai ini tidak disimpan di DB.
 */
final readonly class EffectiveSchedule
{
    public const SHIFT = 'shift';

    public const HOLIDAY = 'holiday';

    public const OFF = 'off';

    public const UNSCHEDULED = 'unscheduled';

    public const FROM_OVERRIDE = 'override';

    public const FROM_PATTERN = 'pattern';

    public const FROM_HOLIDAY = 'holiday';

    public const FROM_NONE = 'none';

    public function __construct(
        public string $kind,
        public string $source,
        public ?Shift $shift = null,
        /** Nama libur pada tanggal itu (juga terisi saat pegawai dijadwalkan masuk di hari libur). */
        public ?string $holidayName = null,
        /** Catatan pengecualian, bila ada. */
        public ?string $note = null,
        /** Ada baris `schedule_overrides` pada tanggal itu (dipakai penanda di kalender). */
        public bool $hasOverride = false,
        /** ID baris `schedule_overrides` itu (ubah/hapus dari kalender). */
        public ?int $overrideId = null,
    ) {}

    public function isWorkday(): bool
    {
        return $this->kind === self::SHIFT;
    }

    /**
     * Bentuk ringkas untuk halaman (kalender & detail pegawai).
     *
     * @return array{kind: string, source: string, shift: array{id: int, code: string, name: string, color: string}|null, holiday: string|null, note: string|null, override: bool, override_id: int|null}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'source' => $this->source,
            'shift' => $this->shift === null ? null : [
                'id' => $this->shift->id,
                'code' => $this->shift->code,
                'name' => $this->shift->name,
                'color' => $this->shift->color,
            ],
            'holiday' => $this->holidayName,
            'note' => $this->note,
            'override' => $this->hasOverride,
            'override_id' => $this->overrideId,
        ];
    }
}
