<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Attendance\Actions\CancelCorrection;
use App\Domain\Attendance\Actions\SubmitCorrection;
use App\Domain\Attendance\Listings\MyCorrectionListing;
use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Domain\Attendance\Support\CorrectionPayload;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\StoreCorrectionRequest;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Api\RequestId;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Koreksi presensi milik sendiri di Pakis Pro (05 §5.7, T-040). Izin `attendance_corrections.create`
 * + perangkat terikat dicek middleware; pegawai selalu dari akun pemilik token (SEC-033).
 */
class AttendanceCorrectionController extends Controller
{
    #[UsesListing(MyCorrectionListing::class)]
    public function index(Request $request): JsonResponse
    {
        $employeeId = self::employeeId($request);
        $query = AttendanceCorrection::query()->with(CorrectionPayload::RELATIONS)->where('attendance_corrections.employee_id', $employeeId);

        return response()->json(
            ListingQuery::apply($query, new MyCorrectionListing, $request)
                ->through(fn (AttendanceCorrection $c) => CorrectionPayload::toArray($c))
                ->toApi(),
        );
    }

    public function store(StoreCorrectionRequest $request, SubmitCorrection $action): JsonResponse
    {
        self::employeeId($request);
        $correction = $action->handle(self::user($request), $request->correctionData(), $request->file('attachment'));

        return self::respond($correction, 201);
    }

    public function cancel(Request $request, int $id, CancelCorrection $action): JsonResponse
    {
        $correction = $action->byOwner(self::user($request), $id);
        $correction->load(CorrectionPayload::RELATIONS);

        return self::respond($correction, 200);
    }

    public static function respond(AttendanceCorrection $correction, int $status): JsonResponse
    {
        return response()->json([
            'data' => CorrectionPayload::toArray($correction),
            'meta' => ['request_id' => RequestId::current()],
        ], $status);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new ApiError(ErrorCode::UNAUTHENTICATED);
        }

        return $user;
    }

    /** Akun tanpa data pegawai tidak punya presensi untuk dikoreksi. */
    private static function employeeId(Request $request): int
    {
        $employeeId = self::user($request)->employee_id;
        if ($employeeId === null) {
            throw new ApiError(ErrorCode::FORBIDDEN);
        }

        return (int) $employeeId;
    }
}
