<?php

use App\Domain\Employee\Models\Employee;
use App\Domain\Settings\SettingRegistry;
use App\Domain\Settings\SettingStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // Unit/Listing butuh DB sungguhan: escape LIKE & collation hanya bisa diuji di MariaDB.
    ->in('Feature', 'Contract', 'Unit/Listing');

require_once __DIR__.'/Fixtures/Listing/helpers.php';

/**
 * Setel nilai setting untuk test. Sengaja MELEWATI validasi registri (mis. sync_max_rows = 2 untuk
 * menguji jalur antrean dengan data kecil); hanya untuk test.
 */
function setSetting(string $key, mixed $value): void
{
    $store = app(SettingStore::class);
    $store->write([$key => $value]);
    $store->forget(SettingRegistry::get($key)->group());
}

/**
 * Pastikan baris pegawai dengan ID ini ada, lalu kembalikan ID-nya. Sejak T-024 `users.employee_id`
 * ber-FK, jadi test scope yang memakai ID pegawai tetap (FakeOrgStructure) butuh baris nyata.
 */
function employeeRow(int $id): int
{
    if (! Employee::withTrashed()->whereKey($id)->exists()) {
        $employee = Employee::factory()->make();
        $employee->forceFill(['id' => $id])->save();
    }

    return $id;
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
