<?php

use App\Domain\Access\DataScope;

/*
 * T-085A (BR-170) regresi: `routes/admin.php` membaca ImportRegistry::routePattern() saat rute
 * dimuat. Itu tidak boleh membuat objek tipe impor, karena konstruktor/dependensinya menarik
 * DataScope (terikat ke OrgStructure asli) sebelum test sempat memasang FakeOrgStructure. Gejala
 * sebelumnya: PermissionMatrixTest 403→404, ScopeTest & ScopedBindingTest merah.
 */

test('BR-170 memuat rute impor tidak mengikat DataScope lebih awal', function () {
    expect(app()->resolved(DataScope::class))->toBeFalse();
});
