<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Attendance\Actions\DecideCorrection;
use App\Domain\Attendance\Listings\ApprovalCorrectionListing;
use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Domain\Attendance\Support\CorrectionAuthority;
use App\Domain\Attendance\Support\CorrectionPayload;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\DecideCorrectionRequest;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Persetujuan koreksi presensi di Pakis Pro (05 §5.8, 06 §6, T-040). Izin
 * `attendance_corrections.approve` dicek middleware; koreksi di luar wewenang → 404, milik sendiri
 * → 403 `CORRECTION_SELF_APPROVAL`, sudah diputuskan → 409 `STATE_CONFLICT` (DecideCorrection).
 */
class ApprovalController extends Controller
{
    #[UsesListing(ApprovalCorrectionListing::class)]
    public function corrections(Request $request, CorrectionAuthority $authority): JsonResponse
    {
        $query = $authority->constrainDecidable(AttendanceCorrection::query()->with(CorrectionPayload::RELATIONS), self::user($request));

        return response()->json(
            ListingQuery::apply($query, new ApprovalCorrectionListing, $request)
                ->through(fn (AttendanceCorrection $c) => CorrectionPayload::toArray($c))
                ->toApi(),
        );
    }

    public function approve(DecideCorrectionRequest $request, int $id, DecideCorrection $action): JsonResponse
    {
        $correction = $action->approve(self::user($request), $id, $request->note());

        return AttendanceCorrectionController::respond($correction, 200);
    }

    public function reject(DecideCorrectionRequest $request, int $id, DecideCorrection $action): JsonResponse
    {
        $correction = $action->reject(self::user($request), $id, (string) $request->note());

        return AttendanceCorrectionController::respond($correction, 200);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new ApiError(ErrorCode::UNAUTHENTICATED);
        }

        return $user;
    }
}
