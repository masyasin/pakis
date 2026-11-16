<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Attendance\Actions\DecideFieldTrip;
use App\Domain\Attendance\Actions\RequestFieldTrip;
use App\Domain\Attendance\Listings\ApprovalFieldTripListing;
use App\Domain\Attendance\Listings\MyFieldTripListing;
use App\Domain\Attendance\Models\FieldTrip;
use App\Domain\Attendance\Support\FieldTripApprovals;
use App\Domain\Attendance\Support\FieldTripPayload;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\StoreFieldTripRequest;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Api\RequestId;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Tugas luar kota di Pakis Pro (05 §5.10, T-036A bagian 2): daftar & pengajuan milik sendiri
 * (`field_trips.request`) dan persetujuan (`field_trips.approve`, 06 §6). Pegawai selalu dari akun
 * pemilik token (SEC-033); pengajuan di luar wewenang → 404, milik sendiri → 403, sudah diputuskan
 * atau bentrok tugas lain → 409.
 */
class FieldTripController extends Controller
{
    #[UsesListing(MyFieldTripListing::class)]
    public function index(Request $request): JsonResponse
    {
        $employeeId = self::employeeId($request);
        $query = FieldTrip::query()->with(FieldTripPayload::RELATIONS)
            ->whereHas('employees', fn ($q) => $q->where('employees.id', $employeeId));

        return response()->json(
            ListingQuery::apply($query, new MyFieldTripListing, $request)
                ->through(fn (FieldTrip $t) => FieldTripPayload::toArray($t))
                ->toApi(),
        );
    }

    public function store(StoreFieldTripRequest $request, RequestFieldTrip $action): JsonResponse
    {
        self::employeeId($request);
        try {
            $trip = $action->handle(self::user($request), $request->employeeId(), $request->tripData(), $request->destinations());
        } catch (ValidationException $e) {
            // Tumpang tindih (FieldTripGuard) menunjuk `employee_ids`, yang tidak ada di form HP.
            throw ValidationException::withMessages(['start_date' => collect($e->errors())->flatten()->all()]);
        }

        return self::respond($trip, 201);
    }

    #[UsesListing(ApprovalFieldTripListing::class)]
    public function approvals(Request $request, FieldTripApprovals $approvals): JsonResponse
    {
        $query = $approvals->constrainDecidable(FieldTrip::query()->with(FieldTripPayload::RELATIONS), self::user($request));

        return response()->json(
            ListingQuery::apply($query, new ApprovalFieldTripListing, $request)
                ->through(fn (FieldTrip $t) => FieldTripPayload::toArray($t))
                ->toApi(),
        );
    }

    public function approve(Request $request, int $id, DecideFieldTrip $action): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);
        $note = is_string($data['note'] ?? null) ? $data['note'] : null;

        return self::respond($action->approve(self::user($request), $id, $note), 200);
    }

    public function reject(Request $request, int $id, DecideFieldTrip $action): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:255']], [
            'note.required' => 'Alasan penolakan wajib diisi.',
            'note.min' => 'Alasan penolakan minimal 5 karakter.',
        ]);

        return self::respond($action->reject(self::user($request), $id, (string) $data['note']), 200);
    }

    private static function respond(FieldTrip $trip, int $status): JsonResponse
    {
        $trip->load(FieldTripPayload::RELATIONS);

        return response()->json([
            'data' => FieldTripPayload::toArray($trip),
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

    private static function employeeId(Request $request): int
    {
        $employeeId = self::user($request)->employee_id;
        if ($employeeId === null) {
            throw new ApiError(ErrorCode::FORBIDDEN);
        }

        return (int) $employeeId;
    }
}
