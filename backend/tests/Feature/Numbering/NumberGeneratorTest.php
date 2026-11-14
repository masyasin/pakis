<?php

use App\Domain\Numbering\Enums\ResetPeriod;
use App\Domain\Numbering\Models\DocumentSequence;
use App\Domain\Numbering\NumberGenerator;
use Carbon\CarbonImmutable;
use Database\Seeders\SequenceSeeder;
use Illuminate\Support\Facades\DB;

/*
 * BR-025 / ADR-009: nomor dibuat server dengan row lock di transaksi pemanggil.
 */

beforeEach(function () {
    $this->seed(SequenceSeeder::class);
    $this->numbers = app(NumberGenerator::class);
});

function nextNumber(string $code, mixed ...$args): string
{
    return DB::transaction(fn () => test()->numbers->next($code, ...$args));
}

test('BR-025 nomor berurutan dari 1 dalam satu periode', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00', 'UTC'));

    expect(nextNumber('employee'))->toBe('PJA-260001')
        ->and(nextNumber('employee'))->toBe('PJA-260002');
});

test('BR-025 reset bulanan: KOR-202612-0001 setelah KOR-202611-0042', function () {
    $sequence = DocumentSequence::query()->where('code', 'attendance_correction')->sole();
    $sequence->counters()->create(['scope_key' => '2026-11', 'last_number' => 41]);

    $this->travelTo(CarbonImmutable::parse('2026-11-30 10:00', 'UTC'));
    expect(nextNumber('attendance_correction'))->toBe('KOR-202611-0042');

    $this->travelTo(CarbonImmutable::parse('2026-12-01 01:00', 'UTC'));
    expect(nextNumber('attendance_correction'))->toBe('KOR-202612-0001');
});

test('BR-025 periode memakai tanggal LOKAL, bukan UTC', function () {
    // 30 Nov 2026 17:30 UTC = 1 Des 2026 00:30 WIB → sudah periode Desember.
    $this->travelTo(CarbonImmutable::parse('2026-11-30 17:30', 'UTC'));

    expect(nextNumber('attendance_correction'))->toBe('KOR-202612-0001');
});

test('BR-025 reset never tidak pernah kembali ke 1', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-31 10:00', 'UTC'));
    nextNumber('employee');
    $this->travelTo(CarbonImmutable::parse('2027-01-02 10:00', 'UTC'));

    // {YY} berubah, counter tetap lanjut karena reset = never.
    expect(nextNumber('employee'))->toBe('PJA-270002');
});

test('BR-025 pola dengan {LOC} punya counter terpisah per lokasi', function () {
    DocumentSequence::query()->create(['code' => 'uji_lokasi', 'name' => 'Uji', 'pattern' => 'SJ/{LOC}/{SEQ:3}', 'reset_period' => ResetPeriod::Never]);

    expect(nextNumber('uji_lokasi', 'GDG01'))->toBe('SJ/GDG01/001')
        ->and(nextNumber('uji_lokasi', 'TKO02'))->toBe('SJ/TKO02/001')
        ->and(nextNumber('uji_lokasi', 'GDG01'))->toBe('SJ/GDG01/002');
});

test('BR-025 nomor yang sudah dipakai dilompati', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00', 'UTC'));
    $taken = ['PJA-260001', 'PJA-260002'];

    expect(nextNumber('employee', isTaken: fn ($n) => in_array($n, $taken, true)))->toBe('PJA-260003')
        ->and(nextNumber('employee'))->toBe('PJA-260004');
});

test('BR-025 lebih dari 50 nomor terpakai berturut-turut → galat konfigurasi', function () {
    expect(fn () => nextNumber('employee', isTaken: fn () => true))
        ->toThrow(RuntimeException::class, 'nomor berturut-turut sudah terpakai');
});

test('nomor ikut di-rollback bila transaksi pemanggil gagal', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00', 'UTC'));

    rescue(fn () => DB::transaction(function () {
        $this->numbers->next('employee');
        throw new RuntimeException('simpan dokumen gagal');
    }), report: false);

    expect(nextNumber('employee'))->toBe('PJA-260001');
});

test('pratinjau tidak menaikkan counter', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00', 'UTC'));
    $sequence = DocumentSequence::query()->where('code', 'employee')->sole();
    nextNumber('employee');

    expect($this->numbers->preview($sequence))->toBe(['PJA-260002', 'PJA-260003', 'PJA-260004'])
        ->and($this->numbers->preview($sequence))->toBe(['PJA-260002', 'PJA-260003', 'PJA-260004'])
        ->and(nextNumber('employee'))->toBe('PJA-260002');
});

test('kode penomoran tidak terdaftar ditolak dengan pesan jelas', function () {
    expect(fn () => nextNumber('tidak_ada'))->toThrow(LogicException::class, 'belum terdaftar');
});
