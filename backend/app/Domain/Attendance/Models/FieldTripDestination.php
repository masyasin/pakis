<?php

namespace App\Domain\Attendance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu titik tujuan tugas luar kota beraturan `destination` (BR-071). Satu tugas bisa beberapa
 * titik (keputusan Mas 16 Nov 2026, mis. dua toko mitra di dua kota); absen sah di sekitar titik
 * mana pun. Radius NULL = `attendance.default_radius_m`.
 *
 * @property int $id
 * @property int $field_trip_id
 * @property string $name
 * @property string $latitude
 * @property string $longitude
 * @property int|null $radius_m
 * @property int $sort_order
 */
class FieldTripDestination extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'field_trip_id' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'radius_m' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
