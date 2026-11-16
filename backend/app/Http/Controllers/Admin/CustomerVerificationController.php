<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\DataScope;
use App\Domain\Customer\Actions\CheckCustomerOtp;
use App\Domain\Customer\Actions\RecordOfficeCall;
use App\Domain\Customer\Actions\SendCustomerOtp;
use App\Domain\Customer\Actions\SubmitVerification;
use App\Domain\Customer\Duplicates\DuplicateCandidate;
use App\Domain\Customer\Duplicates\DuplicateFinder;
use App\Domain\Customer\Enums\VerificationMethod;
use App\Domain\Customer\Enums\VerificationStatus;
use App\Domain\Customer\Listings\CustomerVerificationListing;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerVerification;
use App\Domain\Customer\Support\PhoneConflicts;
use App\Domain\Customer\Support\VerificationState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CustomerRequest;
use App\Http\Requests\Customer\SubmitVerificationRequest;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use App\Support\Phone\PhoneNormalizer;
use App\Support\Time\DisplayTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Verifikasi kios, cek duplikat, dan OTP (15 §5, BR-165/166, SEC-105, T-074).
 *
 * - `index`: antrean verifikasi (`customers.verify`), scope Pelanggan.
 * - `duplicates`, `sendOtp`, `checkOtp`: endpoint JSON (`{data: …}`; galat format 05 §2.2 karena
 *   klien mengirim `Accept: application/json`). Layar memanggilnya tanpa pindah halaman.
 * - `submit` (`admin.customers.verify`) & `officeCall`: form Inertia (redirect + toast).
 */
class CustomerVerificationController extends Controller
{
    #[UsesListing(CustomerVerificationListing::class)]
    public function index(Request $request): Response
    {
        Gate::authorize('viewVerifications', Customer::class);
        $user = self::user($request);
        $fullPhone = $user->can('viewFullPhone', Customer::class);

        $result = ListingQuery::apply(CustomerVerificationListing::query($user), new CustomerVerificationListing, $request)
            ->through(fn (CustomerVerification $v) => [
                ...array_diff_key(VerificationState::row($v), ['office_call_note' => true]),
                'customer' => $v->customer !== null ? [
                    'id' => $v->customer->id,
                    'code' => $v->customer->code,
                    'store_name' => $v->customer->store_name,
                    'owner_name' => $v->customer->owner_name,
                    'sales_area' => $v->customer->salesArea?->name,
                ] : null,
                'phone_target' => $fullPhone ? $v->phone_target : PhoneNormalizer::mask($v->phone_target),
            ]);

        return Inertia::render('customer-verifications/index', [
            ...$result->toInertia(),
            'phoneMasked' => ! $fullPhone,
            'statusOptions' => VerificationStatus::options(),
            'methodOptions' => VerificationMethod::options(),
        ]);
    }

    /**
     * Kandidat duplikat untuk form kios (BR-166(2)). Pencarian lintas seluruh kios; kios di luar
     * scope pengguna hanya ditampilkan kode, nama toko, dan alasannya (PA-52).
     */
    public function duplicates(Request $request, DuplicateFinder $finder, PhoneConflicts $phones, DataScope $scope): JsonResponse
    {
        $user = self::user($request);
        $input = [
            'store_name' => is_string($request->input('store_name')) ? trim($request->input('store_name')) : $request->input('store_name'),
            'owner_phone' => PhoneNormalizer::forInput($request->input('owner_phone')),
            'latitude' => $request->input('latitude') === '' ? null : $request->input('latitude'),
            'longitude' => $request->input('longitude') === '' ? null : $request->input('longitude'),
            'village_code' => $request->input('village_code') === '' ? null : $request->input('village_code'),
            'customer_id' => $request->input('customer_id') === '' ? null : $request->input('customer_id'),
        ];
        $validator = Validator::make($input, [
            'store_name' => ['required', 'string', 'min:2', 'max:150'],
            'owner_phone' => ['nullable', 'string', 'regex:'.PhoneNormalizer::STORED_PATTERN],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'village_code' => ['nullable', 'string', 'regex:'.CustomerRequest::VILLAGE_PATTERN],
            'customer_id' => ['nullable', 'integer', 'min:1'],
        ], ['owner_phone.regex' => PhoneNormalizer::INVALID_MESSAGE]);
        if ($validator->fails()) {
            throw new ApiError(ErrorCode::VALIDATION_FAILED, $validator->errors()->toArray());
        }
        /** @var array{store_name: string, owner_phone: string|null, latitude: mixed, longitude: mixed, village_code: string|null, customer_id: mixed} $data */
        $data = $validator->validated() + ['owner_phone' => null, 'latitude' => null, 'longitude' => null, 'village_code' => null, 'customer_id' => null];

        // Kios yang sedang diubah harus dalam scope (IDOR, SEC-033).
        $exceptId = $data['customer_id'] === null ? null : (int) $data['customer_id'];
        if ($exceptId !== null && ! $scope->allowsCustomer($user, $exceptId)) {
            throw new ApiError(ErrorCode::NOT_FOUND);
        }

        $phoneConflict = null;
        if ($data['owner_phone'] !== null) {
            try {
                $phones->assertUsable($data['owner_phone'], $exceptId);
            } catch (ApiError $e) {
                $phoneConflict = ['code' => $e->errorCode->value, 'message' => $e->publicMessage()];
            }
        }

        $candidates = $finder->find(
            $data['store_name'],
            $data['owner_phone'],
            $data['latitude'] === null ? null : (string) $data['latitude'],
            $data['longitude'] === null ? null : (string) $data['longitude'],
            $data['village_code'],
            $exceptId,
        );
        $fullPhone = $user->can('viewFullPhone', Customer::class);

        return response()->json(['data' => [
            'phone_conflict' => $phoneConflict,
            'candidates' => array_map(function (DuplicateCandidate $c) use ($scope, $user, $fullPhone) {
                $customer = $c->customer;
                $base = [
                    'code' => $customer->code,
                    'store_name' => $customer->store_name,
                    'status' => $customer->status->value,
                    'status_label' => $customer->status->label(),
                    'reasons' => $c->reasons,
                    'similarity' => $c->similarity,
                    'distance_m' => $c->distanceM,
                ];
                if (! $scope->allowsCustomer($user, $customer->id)) {
                    return [...$base, 'id' => null, 'in_scope' => false];
                }

                return [
                    ...$base,
                    'id' => $customer->id,
                    'in_scope' => true,
                    'owner_name' => $customer->owner_name,
                    'owner_phone' => $fullPhone ? $customer->owner_phone : PhoneNormalizer::mask($customer->owner_phone),
                    'village' => $customer->village?->name,
                    'sales_area' => $customer->salesArea?->name,
                ];
            }, $candidates),
        ]]);
    }

    public function submit(SubmitVerificationRequest $request, Customer $customer, SubmitVerification $action): RedirectResponse
    {
        $photo = $request->file('photo');
        abort_unless($photo instanceof UploadedFile, 422);
        $verification = $action->handle($customer, $request->verificationData(), $photo, self::user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => $verification === null
            ? 'Verifikasi dikirim. Menunggu telepon kantor ke pemilik kios oleh petugas lain.'
            : 'Verifikasi dikirim dan menunggu persetujuan.']);

        return to_route('admin.customers.show', ['customer' => $customer->id, 'tab' => 'verifikasi']);
    }

    /** Catat telepon kantor (BR-165b): pengguna selain pengaju. */
    public function officeCall(Request $request, Customer $customer, RecordOfficeCall $action): RedirectResponse
    {
        Gate::authorize('submitVerification', $customer);
        $data = $request->validate([
            'office_call_note' => ['required', 'string', 'min:10', 'max:500'],
        ], [
            'office_call_note.required' => 'Tulis ringkasan telepon dengan pemilik kios.',
            'office_call_note.min' => 'Ringkasan telepon minimal 10 karakter.',
        ]);
        $action->handle($customer, (string) $data['office_call_note'], self::user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Telepon kantor dicatat. Verifikasi menunggu persetujuan.']);

        return to_route('admin.customers.show', ['customer' => $customer->id, 'tab' => 'verifikasi']);
    }

    public function sendOtp(Request $request, Customer $customer, SendCustomerOtp $action): JsonResponse
    {
        Gate::authorize('submitVerification', $customer);
        $row = $action->handle($customer, self::user($request));

        // Kode TIDAK pernah dikirim ke klien; hanya masa berlaku & nomor termasking.
        return response()->json(['data' => [
            'phone' => PhoneNormalizer::mask($row->phone),
            'expires_at' => DisplayTime::toLocal($row->expires_at)->toIso8601String(),
            'length' => (int) setting('auth.otp_length'),
        ]]);
    }

    public function checkOtp(Request $request, Customer $customer, CheckCustomerOtp $action): JsonResponse
    {
        Gate::authorize('submitVerification', $customer);
        $length = (int) setting('auth.otp_length');
        $validator = Validator::make(['code' => is_string($request->input('code')) ? trim($request->input('code')) : $request->input('code')], [
            'code' => ['required', 'string', 'regex:/^\d{'.$length.'}$/'],
        ], ['code.required' => 'Masukkan kode OTP.', 'code.regex' => 'Kode OTP tidak sesuai format.']);
        if ($validator->fails()) {
            throw new ApiError(ErrorCode::VALIDATION_FAILED, $validator->errors()->toArray());
        }
        $action->handle($customer, (string) $validator->validated()['code']);

        return response()->json(['data' => ['valid' => true]]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
