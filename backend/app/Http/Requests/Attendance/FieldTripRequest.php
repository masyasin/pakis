<?php

namespace App\Http\Requests\Attendance;

use App\Domain\Attendance\Enums\FieldTripRule;
use App\Domain\Attendance\Models\FieldTrip;
use App\Http\Requests\Attendance\Concerns\ValidatesScheduleTargets;
use App\Http\Requests\Organization\LocationRequest;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Data tugas luar kota (BR-071, 04 §2.13). Izin `field_trips.manage` dicek middleware route; scope
 * pegawai dicek di sini (aktif & dalam scope pelaku, galat umum SEC-033). Titik tujuan wajib untuk
 * aturan `destination` dan diabaikan untuk aturan lain.
 */
class FieldTripRequest extends FormRequest
{
    use ValidatesScheduleTargets;

    /** Batas teknis rombongan per tugas (bukan parameter bisnis). */
    public const MAX_TRIP_EMPLOYEES = 50;

    /**
     * Middleware `can:field_trips.manage` sudah memeriksa izin. Untuk UBAH, Policy diperiksa di sini
     * (sebelum validasi), agar permintaan tanpa wewenang langsung 403 tanpa memproses datanya.
     */
    public function authorize(): bool
    {
        $trip = $this->route('trip');

        return ! $trip instanceof FieldTrip || Gate::allows('update', $trip);
    }

    protected function prepareForValidation(): void
    {
        $text = fn (string $key) => is_string($this->input($key)) && trim((string) $this->input($key)) !== '' ? trim((string) $this->input($key)) : null;
        $end = $this->input('end_date');

        $this->merge([
            'destination' => $text('destination'),
            'purpose' => $text('purpose'),
            // "Sampai tanggal" kosong = tugas satu hari.
            'end_date' => $end === null || $end === '' ? $this->input('start_date') : $end,
            'destinations' => $this->normalizedDestinations(),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $destination = FieldTripRule::Destination->value;

        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'destination' => ['required', 'string', 'min:2', 'max:150'],
            'purpose' => ['nullable', 'string', 'max:500'],
            'attendance_rule' => ['required', Rule::enum(FieldTripRule::class)],
            'destinations' => ["exclude_unless:attendance_rule,{$destination}", 'required', 'array', 'min:1', 'max:'.FieldTrip::MAX_DESTINATIONS],
            'destinations.*.name' => ["exclude_unless:attendance_rule,{$destination}", 'required', 'string', 'max:150'],
            'destinations.*.latitude' => ["exclude_unless:attendance_rule,{$destination}", 'required', 'numeric', 'between:'.LocationRequest::LAT_MIN.','.LocationRequest::LAT_MAX, 'decimal:0,7'],
            'destinations.*.longitude' => ["exclude_unless:attendance_rule,{$destination}", 'required', 'numeric', 'between:'.LocationRequest::LNG_MIN.','.LocationRequest::LNG_MAX, 'decimal:0,7'],
            'destinations.*.radius_m' => ["exclude_unless:attendance_rule,{$destination}", 'nullable', 'integer', 'between:20,5000'],
            'employee_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_TRIP_EMPLOYEES],
            'employee_ids.*' => ['integer', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'start_date.required' => 'Isi tanggal mulai.',
            'start_date.date_format' => 'Tanggal tidak valid.',
            'end_date.date_format' => 'Tanggal tidak valid.',
            'end_date.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            'destination.required' => 'Isi tujuan tugas.',
            'destinations.required' => 'Tambahkan minimal satu titik tujuan.',
            'destinations.min' => 'Tambahkan minimal satu titik tujuan.',
            'destinations.max' => 'Paling banyak '.FieldTrip::MAX_DESTINATIONS.' titik tujuan per tugas.',
            'destinations.*.name.required' => 'Isi nama tempat.',
            'destinations.*.latitude.required' => 'Tempel tautan Google Maps titik tujuan.',
            'destinations.*.longitude.required' => 'Tempel tautan Google Maps titik tujuan.',
            'destinations.*.latitude.between' => 'Titik tujuan di luar wilayah Indonesia.',
            'destinations.*.longitude.between' => 'Titik tujuan di luar wilayah Indonesia.',
            'employee_ids.required' => 'Pilih minimal satu pegawai.',
            'employee_ids.min' => 'Pilih minimal satu pegawai.',
            'employee_ids.max' => 'Paling banyak '.self::MAX_TRIP_EMPLOYEES.' pegawai per tugas.',
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $start = CarbonImmutable::parse((string) $this->input('start_date'));
                $end = CarbonImmutable::parse((string) $this->input('end_date'));
                $max = (int) setting('attendance.field_trip_max_days');
                if ($start->diffInDays($end) + 1 > $max) {
                    $validator->errors()->add('end_date', "Satu tugas paling lama {$max} hari.");
                }

                // Tugas yang sudah selesai tidak berguna untuk absen dan tidak boleh dibuat/diubah (BR-071).
                if ($end->toDateString() < DisplayTime::toLocal(now())->toDateString()) {
                    $validator->errors()->add('end_date', 'Tanggal selesai sudah lewat.');
                }

                $this->checkEmployees($validator);

                // Maker-checker (06 §1 butir 4, SEC-031): tugas langsung `approved`, jadi tugas untuk
                // diri sendiri sama dengan menyetujui milik sendiri (mis. memberi diri aturan `anywhere`).
                $self = $this->user()?->employee_id;
                if ($self !== null && in_array((int) $self, array_map('intval', (array) $this->input('employee_ids')), true)) {
                    $validator->errors()->add('employee_ids', 'Tugas luar kota untuk diri sendiri harus dibuat atau disetujui orang lain.');
                }
            },
        ];
    }

    /** @return array<string, mixed> kunci = FieldTrip::AUDITED */
    public function tripData(): array
    {
        return $this->safe()->only(FieldTrip::AUDITED);
    }

    /**
     * Titik tujuan tervalidasi; aturan selain `destination` = kosong (titik dibuang).
     *
     * @return list<array{name: string, latitude: float, longitude: float, radius_m: int|null}>
     */
    public function destinations(): array
    {
        if ($this->input('attendance_rule') !== FieldTripRule::Destination->value) {
            return [];
        }

        $rows = $this->validated('destinations', []);

        return array_values(array_map(fn (array $d) => [
            'name' => trim((string) $d['name']),
            'latitude' => (float) $d['latitude'],
            'longitude' => (float) $d['longitude'],
            'radius_m' => isset($d['radius_m']) && $d['radius_m'] !== '' ? (int) $d['radius_m'] : null,
        ], is_array($rows) ? $rows : []));
    }

    /** Baris kosong (semua isian kosong) dari form dibuang sebelum validasi. */
    private function normalizedDestinations(): mixed
    {
        $rows = $this->input('destinations');
        if (! is_array($rows)) {
            return $rows;
        }

        return array_values(array_filter($rows, fn ($d) => is_array($d)
            && (trim((string) ($d['name'] ?? '')) !== '' || trim((string) ($d['latitude'] ?? '')) !== '' || trim((string) ($d['longitude'] ?? '')) !== '')));
    }
}
