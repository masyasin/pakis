<?php

namespace App\Domain\Employee\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Employee\Events\AssignmentChanged;
use App\Domain\Employee\Models\Assignment;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Akhiri penugasan TAMBAHAN (rangkap). Penugasan utama tidak bisa diakhiri di sini (keputusan Mas
 * 15 Nov 2026): pegawai aktif selalu punya satu penugasan utama — pindah lewat Mutasi, keluar lewat
 * Nonaktifkan (T-026).
 */
final class EndAssignment
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Assignment $assignment, string $endDate, ?int $userId): Assignment
    {
        return DB::transaction(function () use ($assignment, $endDate, $userId) {
            $fail = fn (string $message) => throw ValidationException::withMessages(['end_date' => $message]);

            $locked = Assignment::query()->lockForUpdate()->findOrFail($assignment->id);

            if ($locked->is_primary) {
                $fail('Penugasan utama tidak bisa diakhiri. Gunakan Mutasi untuk memindahkan, atau Nonaktifkan bila pegawai keluar.');
            }

            if ($locked->end_date !== null) {
                $fail('Penugasan ini sudah diakhiri.');
            }

            $end = CarbonImmutable::createFromFormat('!Y-m-d', $endDate);
            $today = DisplayTime::toLocal(now())->startOfDay()->toImmutable();

            if ($end === null || $end->lessThan($locked->start_date)) {
                $fail('Tanggal selesai tidak boleh sebelum tanggal mulai penugasan.');
            }

            if ($end->lessThan($today->subDays(MutateEmployee::MAX_BACKDATE_DAYS))) {
                $fail('Tanggal selesai paling jauh '.MutateEmployee::MAX_BACKDATE_DAYS.' hari ke belakang.');
            }

            $before = CreateAssignment::audited($locked);
            $locked->forceFill(['end_date' => $end->toDateString(), 'updated_by' => $userId])->save();

            $this->audit->record('assignment.ended', $locked, $before, CreateAssignment::audited($locked));

            AssignmentChanged::dispatch($locked->employee_id, $end->addDay()->toDateString(), 'ended');

            return $locked;
        });
    }
}
