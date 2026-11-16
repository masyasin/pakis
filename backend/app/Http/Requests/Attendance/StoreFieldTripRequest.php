<?php

namespace App\Http\Requests\Attendance;

use App\Models\User;

/**
 * Ajukan tugas luar kota dari Pakis Pro (05 §5.10, T-036A bagian 2). Aturan tanggal, tujuan,
 * aturan lokasi, dan titik tujuan sama dengan form web (FieldTripRequest); pegawai SELALU pemilik
 * token (tidak ada `employee_ids` dari klien, SEC-033). Izin `field_trips.request` dicek middleware.
 */
class StoreFieldTripRequest extends FieldTripRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $user = $this->user();
        $this->merge(['employee_ids' => $user instanceof User && $user->employee_id !== null ? [(int) $user->employee_id] : []]);
    }

    protected function forbidsOwnTrip(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'employee_ids.required' => 'Akun ini tidak terhubung ke data pegawai.',
            'destinations.*.latitude.required' => 'Tempel tautan Google Maps lengkap untuk titik tujuan.',
            'destinations.*.longitude.required' => 'Tempel tautan Google Maps lengkap untuk titik tujuan.',
        ];
    }

    public function employeeId(): int
    {
        return (int) ($this->validated('employee_ids')[0] ?? 0);
    }
}
