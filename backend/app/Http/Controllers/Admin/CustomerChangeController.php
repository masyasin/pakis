<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Customer\Actions\ChangeCustomerStatus;
use App\Domain\Customer\Actions\PhoneChangeOtp;
use App\Domain\Customer\Actions\RequestCreditLimit;
use App\Domain\Customer\Actions\RequestMerge;
use App\Domain\Customer\Actions\RequestPhoneChange;
use App\Domain\Customer\Enums\VerificationStatus;
use App\Domain\Customer\Models\Customer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\ChangePhoneRequest;
use App\Http\Requests\Customer\CreditLimitRequest;
use App\Http\Requests\Customer\CustomerStatusRequest;
use App\Http\Requests\Customer\MergeCustomerRequest;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Phone\PhoneNormalizer;
use App\Support\Time\DisplayTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

/**
 * Perubahan kios lewat approval & transisi status (15 §5, BR-125, BR-126, BR-166(4)(5), §6.6, T-074):
 * ganti HP (+ OTP ke HP lama, JSON), limit kredit, gabung, dan tahan/nonaktif. Kios di luar scope
 * = 404 lewat binding ber-scope; izin per aksi lewat middleware route + Policy.
 */
class CustomerChangeController extends Controller
{
    public function changePhone(ChangePhoneRequest $request, Customer $customer, RequestPhoneChange $action): RedirectResponse
    {
        $change = $action->handle($customer, $request->changeData(), self::user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => $change->status === VerificationStatus::Approved
            ? 'HP pemilik kios diganti.'
            : 'Ganti HP diajukan dan menunggu persetujuan.']);

        return to_route('admin.customers.show', ['customer' => $customer->id, 'tab' => 'verifikasi']);
    }

    /** OTP ganti HP dikirim ke HP LAMA pemilik (JSON; kode tidak pernah dikembalikan). */
    public function sendPhoneOtp(Request $request, Customer $customer, PhoneChangeOtp $action): JsonResponse
    {
        Gate::authorize('changePhone', $customer);
        $row = $action->send($customer, self::user($request));

        return response()->json(['data' => [
            'phone' => PhoneNormalizer::mask($row->phone),
            'expires_at' => DisplayTime::toLocal($row->expires_at)->toIso8601String(),
            'length' => (int) setting('auth.otp_length'),
        ]]);
    }

    public function checkPhoneOtp(Request $request, Customer $customer, PhoneChangeOtp $action): JsonResponse
    {
        Gate::authorize('changePhone', $customer);
        $length = (int) setting('auth.otp_length');
        $validator = Validator::make(['code' => is_string($request->input('code')) ? trim($request->input('code')) : $request->input('code')], [
            'code' => ['required', 'string', 'regex:/^\d{'.$length.'}$/'],
        ], ['code.required' => 'Masukkan kode OTP.', 'code.regex' => 'Kode OTP tidak sesuai format.']);
        if ($validator->fails()) {
            throw new ApiError(ErrorCode::VALIDATION_FAILED, $validator->errors()->toArray());
        }
        $action->check($customer, (string) $validator->validated()['code']);

        return response()->json(['data' => ['valid' => true]]);
    }

    public function creditLimit(CreditLimitRequest $request, Customer $customer, RequestCreditLimit $action): RedirectResponse
    {
        /** @var array{credit_limit: string, reason: string} $data */
        $data = $request->validated();
        $approval = $action->handle($customer, $data['credit_limit'], $data['reason'], self::user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => $approval === null
            ? 'Limit kredit diperbarui.'
            : 'Perubahan limit kredit diajukan dan menunggu persetujuan.']);

        return to_route('admin.customers.show', ['customer' => $customer->id, 'tab' => 'limit']);
    }

    public function merge(MergeCustomerRequest $request, Customer $customer, RequestMerge $action): RedirectResponse
    {
        /** @var array{reason: string} $data */
        $data = $request->validated();
        $approval = $action->handle($customer, $request->target(), $data['reason'], self::user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => $approval === null
            ? 'Kios digabung.'
            : 'Penggabungan kios diajukan dan menunggu persetujuan.']);

        return to_route('admin.customers.show', ['customer' => $customer->id]);
    }

    public function status(CustomerStatusRequest $request, Customer $customer, ChangeCustomerStatus $action): RedirectResponse
    {
        /** @var array{reason: string} $data */
        $data = $request->validated();
        $saved = $action->handle($customer, $request->action(), $data['reason'], self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Status kios \"{$saved->store_name}\" kini {$saved->status->label()}."]);

        return to_route('admin.customers.show', ['customer' => $customer->id]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
