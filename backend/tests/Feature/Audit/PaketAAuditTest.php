<?php

/*
 * T-087 — SEC-110 data-driven (15 §10.2): setiap aksi sensitif Paket A punya event audit yang
 * (1) benar-benar dicatat kode aplikasi dan (2) diperiksa oleh test alur aksinya (AuditLog dengan
 * event itu). Menghapus panggilan audit atau assertion-nya membuat test ini merah. Ekspor massal
 * (`mass_export`) diuji di CostVisibilityTest & ExportAuditTest.
 */

/** Aksi SEC-110 → event audit. */
const SEC_110_ACTIONS = [
    'perubahan aturan approval' => 'approval_rule.updated',
    'delegasi' => 'approval_delegation.created',
    'limit kredit' => 'customer.credit_limit_changed',
    'HP kios' => 'customer.phone_changed',
    'penggabungan kios' => 'customer.merged',
    'terbit harga' => 'price_version.published',
    'aktivasi promo' => 'promotion.activated',
    'posting opname' => 'stock_opname.posted',
    'pemusnahan' => 'disposal_report.executed',
    'lepas karantina' => 'stock.released',
    'tutup saldo awal' => 'inventory.opening_balance_closed',
    'ubah penanda produk' => 'product.flags_relaxed',
];

/** @return list<string> isi berkas PHP di bawah folder, kecuali berkas ini sendiri */
function sec110Sources(string $dir): array
{
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && $file->getRealPath() !== realpath(__FILE__)) {
            $out[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    return $out;
}

test('SEC-110 aksi sensitif dicatat AuditLogger di kode aplikasi', function (string $event) {
    $emitters = array_keys(array_filter(sec110Sources(app_path()), fn (string $src) => str_contains($src, "'{$event}'")));

    expect($emitters)->not->toBeEmpty("Event audit [{$event}] tidak dicatat di app/.");
})->with(SEC_110_ACTIONS);

test('SEC-110 test alur aksi memeriksa AuditLog event itu', function (string $event) {
    $tests = array_keys(array_filter(sec110Sources(base_path('tests/Feature')),
        fn (string $src) => str_contains($src, "'{$event}'") && str_contains($src, 'AuditLog')));

    expect($tests)->not->toBeEmpty("Belum ada test yang memeriksa AuditLog [{$event}].");
})->with(SEC_110_ACTIONS);
