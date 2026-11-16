<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\CorrectionStatus;
use App\Domain\Attendance\Enums\CorrectionType;
use App\Domain\Attendance\Support\CorrectionAuthority;
use App\Domain\Employee\Models\Employee;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Koreksi presensi (04 §2.13, BR-069, T-040). Jam usulan `HH:mm:ss` lokal pada `work_date`;
 * diterapkan ke `attendance_days` saat disetujui (ApplyCorrection), log asli tetap utuh.
 * Presensi manual (keputusan Mas 16 Nov 2026) = koreksi yang dibuat langsung `approved` oleh
 * pengisi selain pegawai itu sendiri.
 *
 * @property int $id
 * @property string $number
 * @property int $employee_id
 * @property CarbonImmutable $work_date
 * @property CorrectionType $type
 * @property string|null $proposed_check_in
 * @property string|null $proposed_check_out
 * @property int $reason_id
 * @property string $note
 * @property string|null $attachment_path
 * @property CorrectionStatus $status
 * @property int|null $approver_employee_id
 * @property int $submitted_by
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property string|null $pending_key
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Employee $employee
 * @property-read ReferenceItem $reason
 * @property-read User $submitter
 * @property-read User|null $decider
 * @property-read Employee|null $approver
 */
class AttendanceCorrection extends Model
{
    protected $guarded = ['id', 'pending_key'];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'work_date' => 'date:Y-m-d',
            'type' => CorrectionType::class,
            'reason_id' => 'integer',
            'status' => CorrectionStatus::class,
            'approver_employee_id' => 'integer',
            'submitted_by' => 'integer',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /** @return BelongsTo<ReferenceItem, $this> */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'reason_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsTo<Employee, $this> atasan langsung saat diajukan (06 §6 syarat 4a) */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approver_employee_id')->withTrashed();
    }

    /** `HH:mm` untuk tampilan/API (kolom TIME menyimpan detik). */
    public static function hm(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }

    /** Presensi manual: diisi orang lain, bukan pengajuan pegawai sendiri (BR-069). */
    public function isManual(): bool
    {
        return $this->submitter->employee_id !== $this->employee_id;
    }

    /**
     * Binding `{correction}` web hanya dalam koreksi yang terlihat pengguna (SEC-033): di luar →
     * 404. API memakai `{id}` + CorrectionAuthority sendiri (05 §5.7–§5.8).
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $user = request()->user();
        if (! $user instanceof User) {
            return null;
        }

        $query = self::query()->where($this->qualifyColumn($field ?? $this->getRouteKeyName()), $value);

        return app(CorrectionAuthority::class)->constrainVisible($query, $user)->first();
    }
}
