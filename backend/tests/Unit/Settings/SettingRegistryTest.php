<?php

use App\Domain\Settings\SettingRegistry;
use App\Domain\Settings\SettingType;

/*
 * Registri = 08 §2 (kriteria T-008). Tabel dokumen di-parse, lalu dicocokkan dua arah dengan
 * registri: kunci, tipe, default, publik, batas min–max/≤/maks, dan opsi enum.
 */

/**
 * @return array<string, array{type: string, default: string, validation: string, public: bool}>
 */
function documentedSettings(): array
{
    $markdown = (string) file_get_contents(dirname(__DIR__, 3).'/../docs/08-BUSINESS-RULES.md');
    $section = str($markdown)->after('## 2. Registri Site Setting')->before('## 3.')->toString();

    preg_match_all('/^\|\s*`([a-z_]+\.[a-z_]+)`\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*(✅|❌)\s*\|/mu', $section, $m, PREG_SET_ORDER);

    return collect($m)->mapWithKeys(fn ($row) => [$row[1] => [
        'type' => trim($row[2]),
        'default' => trim($row[3]),
        'validation' => trim($row[4]),
        'public' => $row[5] === '✅',
    ]])->all();
}

function documentedDefault(string $raw, string $type): mixed
{
    $raw = trim($raw, '` ');

    return match (true) {
        $raw === 'kosong', $type === 'image' => null,
        $raw === 'true', $raw === 'false' => $raw === 'true',
        str_starts_with($raw, '[') => json_decode($raw, true),
        preg_match('/^\d+$/', $raw) === 1 && $type !== 'string' => (int) $raw,
        default => $raw,
    };
}

test('dokumen 08 §2 terbaca', function () {
    expect(count(documentedSettings()))->toBeGreaterThan(50);
});

test('setiap kunci di 08 §2 ada di registri dan sebaliknya', function () {
    expect(array_keys(SettingRegistry::all()))->toEqualCanonicalizing(array_keys(documentedSettings()));
});

test('tipe, default, dan penanda publik sama dengan 08 §2', function () {
    $typeMap = ['multi enum' => 'multi_enum'];

    foreach (documentedSettings() as $key => $doc) {
        $definition = SettingRegistry::get($key);
        $docType = $typeMap[$doc['type']] ?? $doc['type'];

        expect($definition->type->value)->toBe($docType, "Tipe {$key}")
            ->and($definition->isPublic)->toBe($doc['public'], "Publik {$key}")
            ->and($definition->cast($definition->default))->toEqual(documentedDefault($doc['default'], $docType), "Default {$key}");
    }
});

test('batas validasi (rentang, ≤, maks) sama dengan 08 §2', function () {
    foreach (documentedSettings() as $key => $doc) {
        $definition = SettingRegistry::get($key);
        $validation = $doc['validation'];

        if (preg_match('/^(\d+)–(\d+)$/u', $validation, $range) === 1) {
            expect([$definition->min, $definition->max])->toBe([(int) $range[1], (int) $range[2]], "Rentang {$key}");
        } elseif (preg_match('/^≤ (\d+)$/u', $validation, $max) === 1) {
            expect($definition->max)->toBe((int) $max[1], "Maks {$key}");
        } elseif (preg_match('/maks (\d+) email/u', $validation, $max) === 1) {
            expect($definition->max)->toBe((int) $max[1], "Maks {$key}");
        }
    }
});

test('opsi enum sama dengan 08 §2', function () {
    foreach (documentedSettings() as $key => $doc) {
        $definition = SettingRegistry::get($key);
        if ($definition->type !== SettingType::Enum) {
            continue;
        }

        preg_match_all('/`?([A-Za-z_\/]+|\d+)`?/', $doc['validation'], $m);
        expect(array_map('strval', $definition->options))->toBe($m[1], "Opsi {$key}");
    }
});

test('tidak ada kunci yang tampak seperti secret di registri (SEC-060)', function () {
    foreach (array_keys(SettingRegistry::all()) as $key) {
        expect($key)->not->toMatch('/password$|secret|api_key|smtp|token$/');
    }
});
