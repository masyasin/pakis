<?php

namespace Tests\Support;

use App\Models\User;

/**
 * Harness IDOR (10 §4, SEC-033, T-019). WAJIB dipakai setiap route modul yang menyentuh data
 * pegawai (`show/update/destroy/approve/...`): data pegawai di luar scope → 404, bukan 403.
 *
 *     uses(Tests\Support\AssertsIdor::class);
 *
 *     test('IDOR admin.employees.show', function () {
 *         $org = FakeOrgStructure::bind();            // atau data penugasan nyata (T-025+)
 *         $org->employee(1)->at(10);
 *         $org->employee(9)->at(20);                   // pegawai lokasi lain
 *         $kepala = User::factory()->create(['employee_id' => employeeRow(1)])->assignRole('kepala_lokasi');
 *         $this->assertOutOfScope404($kepala, 'GET', "/pegawai/{$employee9->id}");
 *     });
 */
trait AssertsIdor
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function assertOutOfScope404(User $actor, string $method, string $uri, array $data = []): void
    {
        $response = $this->actingAs($actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->json($method, $uri, $data);

        $response->assertNotFound();
    }
}
