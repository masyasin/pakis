<?php

namespace App\Http\Requests\Attendance;

use App\Domain\Attendance\Models\Holiday;
use App\Domain\Organization\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Data hari libur (FR-033, 04 §2.13). Izin `holidays.manage` dicek middleware route.
 * Rentang tanggal inklusif; "sampai tanggal" kosong = libur satu hari. Libur khusus lokasi wajib punya ≥ 1 lokasi. Lokasi baru harus aktif (BR-041); lokasi yang
 * sudah terpasang boleh tetap dipertahankan walau kemudian dinonaktifkan.
 */
class HolidayRequest extends FormRequest
{
    /**
     * Batas wajar panjang satu libur (mencegah salah ketik tahun, mis. 2026 → 2062), bukan
     * parameter bisnis: Idul Fitri + cuti bersama pun jauh di bawah ini.
     */
    private const MAX_DAYS = 31;

    public function authorize(): bool
    {
        return true; // middleware `can:holidays.manage`
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $description = $this->input('description');
        $end = $this->input('end_date');

        $this->merge([
            'name' => is_string($name) ? trim($name) : $name,
            'description' => is_string($description) && trim($description) !== '' ? trim($description) : null,
            'end_date' => $end === null || $end === '' ? $this->input('start_date') : $end,
            'is_national' => $this->has('is_national') ? $this->boolean('is_national') : true,
            'applies_to_all' => $this->has('applies_to_all') ? $this->boolean('applies_to_all') : true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $holiday = $this->route('holiday');
        $start = $this->input('start_date');

        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'name' => [
                'required', 'string', 'min:2', 'max:150',
                Rule::unique('holidays', 'name')
                    ->where('start_date', is_string($start) ? $start : '')
                    ->ignore($holiday instanceof Holiday ? $holiday->id : null),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'is_national' => ['boolean'],
            'applies_to_all' => ['boolean'],
            'location_ids' => ['exclude_if:applies_to_all,true', 'required', 'array', 'min:1', 'max:100'],
            'location_ids.*' => ['exclude_if:applies_to_all,true', 'integer', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start_date.date_format' => 'Tanggal tidak valid.',
            'end_date.date_format' => 'Tanggal tidak valid.',
            'end_date.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            'name.unique' => 'Sudah ada hari libur dengan nama ini yang mulai pada tanggal tersebut.',
            'location_ids.required' => 'Pilih minimal satu lokasi, atau pilih "Semua lokasi".',
            'location_ids.min' => 'Pilih minimal satu lokasi, atau pilih "Semua lokasi".',
        ];
    }

    /**
     * Lokasi harus ada dan aktif, kecuali yang sudah terpasang pada libur ini. Dicek sekaligus
     * (satu query) dan dilaporkan di `location_ids` agar tampil di bawah pemilih lokasi.
     *
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $days = CarbonImmutable::parse((string) $this->input('start_date'))->diffInDays(CarbonImmutable::parse((string) $this->input('end_date'))) + 1;
                if ($days > self::MAX_DAYS) {
                    $validator->errors()->add('end_date', 'Satu hari libur paling lama '.self::MAX_DAYS.' hari. Periksa lagi tanggalnya.');
                }
            },
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || $this->boolean('applies_to_all')) {
                    return;
                }

                $ids = $this->locationIds();
                $holiday = $this->route('holiday');
                $attached = $holiday instanceof Holiday ? $holiday->locationIds() : [];
                $active = Location::query()->whereKey($ids)->where('is_active', true)->pluck('id')->map(fn ($id): int => (int) $id)->all();

                if (array_diff($ids, $active, $attached) !== []) {
                    $validator->errors()->add('location_ids', 'Ada lokasi yang tidak ditemukan atau sudah nonaktif.');
                }
            },
        ];
    }

    /** @return array<string, mixed> */
    public function holidayData(): array
    {
        return $this->safe()->only(Holiday::AUDITED);
    }

    /** @return list<int> */
    public function locationIds(): array
    {
        $ids = $this->input('location_ids', []);

        return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
    }
}
