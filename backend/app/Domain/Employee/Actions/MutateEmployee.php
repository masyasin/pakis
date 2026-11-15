<?php

namespace App\Domain\Employee\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mutasi (BR-052): penugasan utama baru + penugasan utama lama otomatis berakhir sehari sebelum
 * tanggal mulai yang baru, dalam SATU transaksi. Mutasi terjadwal (tanggal mulai di masa depan)
 * boleh: penugasan lama tetap berlaku sampai hari sebelumnya.
 */
final class MutateEmployee
{
    /** BR-052: tanggal mulai mutasi paling jauh 31 hari ke belakang. */
    public const MAX_BACKDATE_DAYS = 31;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CreateAssignment $assignments,
    ) {}

    /**
     * @param  array{position_id: int, location_id: int, supervisor_employee_id?: int|null, attendance_mode?: string|null, start_date: string, note?: string|null}  $data
     */
    public function handle(Employee $employee, array $data, ?int $userId): Assignment
    {
        return DB::transaction(function () use ($employee, $data, $userId) {
            $fail = fn (string $key, string $message) => throw ValidationException::withMessages([$key => $message]);

            // Kunci pegawai: dua mutasi bersamaan untuk pegawai yang sama harus antre.
            $locked = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            if (! $locked->isActive()) {
                $fail('start_date', 'Pegawai nonaktif tidak bisa dimutasi. Aktifkan kembali dulu.');
            }

            $start = CarbonImmutable::createFromFormat('!Y-m-d', $data['start_date']);
            $today = DisplayTime::toLocal(now())->startOfDay()->toImmutable();
            if ($start === null || $start->lessThan($today->subDays(self::MAX_BACKDATE_DAYS))) {
                $fail('start_date', 'Tanggal mulai mutasi paling jauh '.self::MAX_BACKDATE_DAYS.' hari ke belakang.');
            }

            $current = Assignment::query()->where('employee_id', $locked->id)
                ->where('is_primary', true)->whereNull('end_date')->lockForUpdate()->first();

            if ($current !== null) {
                if (! $start->greaterThan($current->start_date)) {
                    $fail('start_date', 'Tanggal mulai harus setelah '.$current->start_date->translatedFormat('j F Y').' (mulai penugasan sekarang).');
                }

                $before = CreateAssignment::audited($current);
                $current->forceFill(['end_date' => $start->subDay()->toDateString(), 'updated_by' => $userId])->save();
                $this->audit->record('assignment.ended', $current, $before, [...CreateAssignment::audited($current), 'reason' => 'mutasi']);
            }

            // Satu event `mutated` (dari CreateAssignment) mencakup penutupan yang lama: tanggal
            // berlaku yang terdampak sama-sama mulai tanggal mutasi.
            return $this->assignments->handle($locked, [...$data, 'is_primary' => true], $userId, '', 'mutated');
        });
    }
}
