<?php

namespace App\Domain\Region\Enums;

use App\Domain\Region\Models\District;
use App\Domain\Region\Models\Province;
use App\Domain\Region\Models\Regency;
use App\Domain\Region\Models\Village;
use Illuminate\Database\Eloquent\Model;

/** Tingkat wilayah (04 §2.7), dari atas ke bawah. */
enum RegionLevel: string
{
    case Province = 'province';
    case Regency = 'regency';
    case District = 'district';
    case Village = 'village';

    public function label(): string
    {
        return match ($this) {
            self::Province => 'provinsi',
            self::Regency => 'kab/kota',
            self::District => 'kecamatan',
            self::Village => 'desa/kelurahan',
        };
    }

    /** @return class-string<Model> */
    public function model(): string
    {
        return match ($this) {
            self::Province => Province::class,
            self::Regency => Regency::class,
            self::District => District::class,
            self::Village => Village::class,
        };
    }

    public function table(): string
    {
        return match ($this) {
            self::Province => 'provinces',
            self::Regency => 'regencies',
            self::District => 'districts',
            self::Village => 'villages',
        };
    }

    /** Kolom kode induk; null untuk provinsi. */
    public function parentColumn(): ?string
    {
        return match ($this) {
            self::Province => null,
            self::Regency => 'province_code',
            self::District => 'regency_code',
            self::Village => 'district_code',
        };
    }

    public function parent(): ?self
    {
        return match ($this) {
            self::Province => null,
            self::Regency => self::Province,
            self::District => self::Regency,
            self::Village => self::District,
        };
    }
}
