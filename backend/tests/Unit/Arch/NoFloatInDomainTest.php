<?php

/*
 * ADR-101 (T-069): domain uang & kuantitas Paket A tidak boleh memakai float. Diperiksa per token
 * PHP (bukan teks) agar kata "float" di komentar/string tidak ikut terhitung. Folder domain yang
 * belum dibuat (Inventory, Pricing, Purchasing menyusul T-075/T-077/T-079) dilewati.
 */

const NO_FLOAT_DIRS = [
    'app/Domain/Catalog',
    'app/Domain/Inventory',
    'app/Domain/Pricing',
    'app/Domain/Purchasing',
    'app/Support/Money',
];

/** @return list<string> pelanggaran "berkas:baris token" */
function floatUsages(string $file): array
{
    $found = [];
    $tokens = token_get_all((string) file_get_contents($file));
    foreach ($tokens as $token) {
        if (! is_array($token)) {
            continue;
        }
        [$id, $text, $line] = $token;
        $isFloatName = $id === T_STRING && in_array(strtolower($text), ['float', 'floatval', 'round', 'number_format'], true);
        if ($isFloatName || $id === T_DOUBLE_CAST || $id === T_DNUMBER) {
            $found[] = basename($file).':'.$line.' '.trim($text);
        }
    }

    return $found;
}

test('ADR-101 tidak ada float di Catalog/Inventory/Pricing/Purchasing & Support/Money', function () {
    $root = dirname(__DIR__, 3);
    $violations = [];
    $checked = 0;

    foreach (NO_FLOAT_DIRS as $dir) {
        $path = $root.'/'.$dir;
        if (! is_dir($path)) {
            continue;
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $checked++;
                array_push($violations, ...floatUsages($file->getPathname()));
            }
        }
    }

    expect($checked)->toBeGreaterThan(0)
        ->and($violations)->toBe([]);
});

test('ADR-101 pemeriksa float menangkap tipe, cast, literal, dan fungsi', function () {
    $file = tempnam(sys_get_temp_dir(), 'flt').'.php';
    file_put_contents($file, "<?php\n// float di komentar tidak dihitung\nfunction a(float \$x): float { return (float) 1.5 + floatval('2') + round(1); }\n\$s = 'float';\n");

    expect(floatUsages($file))->toHaveCount(6);
    unlink($file);
});
