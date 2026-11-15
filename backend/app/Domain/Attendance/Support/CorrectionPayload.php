<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Support\Time\DisplayTime;
use Carbon\CarbonInterface;

/**
 * Objek koreksi untuk API (05 §5.7a). Path lampiran tidak pernah dikirim (hanya `has_attachment`);
 * unduhan lewat route ber-izin (T-041). Relasi employee/reason/approver/submitter/decider dimuat
 * pemanggil.
 */
final class CorrectionPayload
{
    /** Relasi yang dibutuhkan toArray(). */
    public const RELATIONS = ['employee', 'reason', 'approver', 'submitter', 'decider'];

    /** @return array<string, mixed> */
    public static function toArray(AttendanceCorrection $c): array
    {
        return [
            'id' => $c->id,
            'number' => $c->number,
            'status' => $c->status->value,
            'work_date' => $c->work_date->toDateString(),
            'type' => $c->type->value,
            'proposed_check_in' => AttendanceCorrection::hm($c->proposed_check_in),
            'proposed_check_out' => AttendanceCorrection::hm($c->proposed_check_out),
            'reason' => ['code' => $c->reason->code, 'name' => $c->reason->name],
            'note' => $c->note,
            'has_attachment' => $c->attachment_path !== null,
            'employee' => ['id' => $c->employee_id, 'employee_number' => $c->employee->employee_number, 'name' => $c->employee->full_name],
            'approver' => $c->approver === null ? null : ['name' => $c->approver->full_name],
            'is_manual' => $c->isManual(),
            'submitted_at' => self::time($c->created_at),
            'decided_at' => self::time($c->decided_at),
            'decided_by' => $c->decider === null ? null : ['name' => $c->decider->name],
            'decision_note' => $c->decision_note,
        ];
    }

    private static function time(?CarbonInterface $moment): ?string
    {
        return $moment === null ? null : DisplayTime::toLocal($moment)->toIso8601String();
    }
}
