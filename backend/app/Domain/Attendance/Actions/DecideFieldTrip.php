<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Enums\FieldTripStatus;
use App\Domain\Attendance\Models\FieldTrip;
use App\Domain\Attendance\Notifications\FieldTripDecided;
use App\Domain\Attendance\Support\FieldTripApprovals;
use App\Domain\Audit\AuditLogger;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Setujui / tolak pengajuan tugas luar kota (T-036A bagian 2, 06 §6 & §7.2a). Baris dikunci lalu
 * status dicek ulang: keputusan ganda → 409 `STATE_CONFLICT`. Milik sendiri → 403 (SEC-031).
 * Di luar wewenang → 404. Saat disetujui, tumpang tindih BR-071 dicek ulang di bawah kunci; bentrok
 * = 409 dengan pesan (tugas lain sudah disetujui di antaranya).
 */
final class DecideFieldTrip
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FieldTripApprovals $approvals,
    ) {}

    public function approve(User $actor, int $tripId, ?string $note): FieldTrip
    {
        return $this->decide($actor, $tripId, FieldTripStatus::Approved, $note);
    }

    public function reject(User $actor, int $tripId, string $note): FieldTrip
    {
        return $this->decide($actor, $tripId, FieldTripStatus::Rejected, $note);
    }

    private function decide(User $actor, int $tripId, FieldTripStatus $to, ?string $note): FieldTrip
    {
        $trip = DB::transaction(function () use ($actor, $tripId, $to, $note) {
            $trip = FieldTrip::query()->lockForUpdate()->find($tripId);
            if ($trip === null) {
                throw new ApiError(ErrorCode::NOT_FOUND);
            }
            if (FieldTripApprovals::isOwn($actor, $trip)) {
                throw new ApiError(ErrorCode::FORBIDDEN, publicMessage: 'Anda tidak boleh memutuskan tugas luar kota milik sendiri.');
            }
            if (! $this->approvals->mayDecide($actor, $trip)) {
                throw new ApiError(ErrorCode::NOT_FOUND);
            }
            if (! FieldTripApprovals::decidable($trip)) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $trip->status->value]);
            }

            if ($to === FieldTripStatus::Approved) {
                try {
                    FieldTripGuard::assertNoOverlap($trip->employeeIds(), $trip->start_date->toDateString(), $trip->end_date->toDateString(), $trip->id);
                } catch (ValidationException $e) {
                    $message = (string) collect($e->errors())->flatten()->first();
                    throw new ApiError(ErrorCode::STATE_CONFLICT, ['start_date' => $message], $message);
                }
            }

            $trip->status = $to;
            $trip->decided_by = $actor->id;
            $trip->decided_at = now();
            $trip->decision_note = $note === null || trim($note) === '' ? null : trim($note);
            $trip->updated_by = $actor->id;
            $trip->save();

            $this->audit->record('field_trip.'.$to->value, $trip, ['status' => FieldTripStatus::Pending->value], [
                'status' => $to->value,
                'number' => $trip->number,
            ]);

            return $trip;
        });

        $requester = User::query()->whereKey($trip->requested_by)->where('is_active', true)->get();
        Notification::send($requester, new FieldTripDecided($trip->number, RequestFieldTrip::period($trip), $to, $trip->decision_note));

        return $trip;
    }
}
