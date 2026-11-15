<?php

namespace App\Http\Requests\Attendance;

use App\Domain\Attendance\Enums\AttendanceType;
use App\Domain\Attendance\Support\AttendanceInput;
use App\Domain\Attendance\Support\SelfieStore;
use App\Domain\Device\Support\SemVer;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Kiriman absen Pakis Pro (05 §5.4, multipart). Izin `attendance.self` dicek middleware route.
 * Galat bentuk → 422 `VALIDATION_FAILED` dengan `details` per field (05 §2.3). Pegawai diambil dari
 * akun pemilik token, BUKAN dari input (absen untuk orang lain tidak mungkin, SEC-033).
 */
class StoreAttendanceRequest extends FormRequest
{
    /** UUID v4 (03 §4.5: dibuat di HP). */
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /** ISO-8601 WAJIB dengan offset (`+07:00` atau `Z`): tanpa offset, jam HP tidak bisa ditafsirkan. */
    private const ISO_WITH_OFFSET = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

    /** Batas ukuran selfie (SEC-022, 05 §5.4), KB. */
    public const SELFIE_MAX_KB = 300;

    public function authorize(): bool
    {
        return true; // middleware `can:attendance.self`
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_uuid' => ['required', 'string', 'regex:'.self::UUID_V4],
            'type' => ['required', 'string', 'in:in,out'],
            'client_time' => ['required', 'string', 'regex:'.self::ISO_WITH_OFFSET, 'date'],
            'is_offline' => ['required', 'boolean'],
            // Rentang wilayah Indonesia (05 §5.4): koordinat di luar ini pasti salah/palsu.
            'latitude' => ['required', 'numeric', 'between:-11,6'],
            'longitude' => ['required', 'numeric', 'between:95,141'],
            'accuracy_m' => ['required', 'numeric', 'min:0', 'max:10000'],
            'is_mock' => ['required', 'boolean'],
            'device_clock_offset_s' => ['nullable', 'integer', 'between:-31536000,31536000'],
            'selfie' => [
                (bool) setting('attendance.require_selfie') ? 'required' : 'nullable',
                'file',
                'max:'.self::SELFIE_MAX_KB,
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! $value instanceof UploadedFile || ! SelfieStore::isGenuineJpeg((string) $value->get())) {
                        $fail('Selfie harus foto JPEG dari kamera.');
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'client_uuid.regex' => 'client_uuid harus UUID v4.',
            'client_time.regex' => 'client_time harus ISO-8601 dengan offset, mis. 2026-11-03T07:41:12+07:00.',
            'latitude.between' => 'Koordinat di luar wilayah Indonesia.',
            'longitude.between' => 'Koordinat di luar wilayah Indonesia.',
            'selfie.required' => 'Selfie wajib.',
            'selfie.max' => 'Ukuran selfie maksimal '.self::SELFIE_MAX_KB.' KB.',
        ];
    }

    public function toInput(): AttendanceInput
    {
        $offset = $this->input('device_clock_offset_s');
        $selfie = $this->file('selfie');

        return new AttendanceInput(
            clientUuid: strtolower((string) $this->input('client_uuid')),
            type: AttendanceType::from((string) $this->input('type')),
            clientTime: CarbonImmutable::parse((string) $this->input('client_time')),
            isOffline: $this->boolean('is_offline'),
            latitude: (float) $this->input('latitude'),
            longitude: (float) $this->input('longitude'),
            accuracyM: (float) $this->input('accuracy_m'),
            isMock: $this->boolean('is_mock'),
            deviceClockOffsetS: $offset === null || $offset === '' ? null : (int) $offset,
            selfie: $selfie instanceof UploadedFile ? $selfie : null,
            appVersion: SemVer::normalize($this->header('X-App-Version')),
        );
    }
}
