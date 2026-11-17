<?php

use App\Domain\Settings\SettingDefinition;
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
        // PA-19: money dibaca sebagai string berskala 2, decimal sebagai string apa adanya.
        $type === 'money' => preg_match('/^\d+$/', $raw) === 1 ? $raw.'.00' : $raw,
        $type === 'decimal' => $raw,
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
    $typeMap = ['multi enum' => 'multi_enum', 'int list' => 'int_list'];

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

        // Batas desimal (mis. `0.5–1`, T-074) disimpan sebagai string agar tidak lewat float.
        $bound = fn (string $raw): int|string => str_contains($raw, '.') ? $raw : (int) $raw;

        if (preg_match('/^(\d+(?:\.\d+)?)–(\d+(?:\.\d+)?)$/u', $validation, $range) === 1) {
            $expected = [$bound($range[1]), $bound($range[2])];
            if ($definition->type->isDecimalString()) {
                // decimal/money: batas boleh int atau string desimal, dibandingkan sebagai teks.
                expect([(string) $definition->min, (string) $definition->max])->toBe(array_map('strval', $expected), "Rentang {$key}");
            } else {
                expect([$definition->min, $definition->max])->toBe($expected, "Rentang {$key}");
            }
        } elseif (preg_match('/^≤ (\d+)$/u', $validation, $max) === 1) {
            expect($definition->max)->toBe((int) $max[1], "Maks {$key}");
        } elseif (preg_match('/^≥ (\d+)$/u', $validation, $min) === 1) {
            expect($definition->min)->toBe((int) $min[1], "Min {$key}");
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

test('T-067 grup approval terdaftar dengan label Persetujuan dan tidak publik', function () {
    expect(SettingRegistry::GROUPS['approval'] ?? null)->toBe('Persetujuan')
        ->and(array_keys(SettingRegistry::group('approval')))->toBe([
            'approval.auto_approve_when_no_rule', 'approval.default_escalation_hours', 'approval.reauth_amount',
        ]);

    foreach (SettingRegistry::group('approval') as $key => $definition) {
        expect($definition->isPublic)->toBeFalse("Publik {$key}");
    }
});

test('PA-19 setiap kunci berada di grup yang terdaftar di GROUPS', function () {
    foreach (SettingRegistry::all() as $key => $definition) {
        expect(array_key_exists($definition->group(), SettingRegistry::GROUPS))->toBeTrue("Grup {$key}");
    }
});

test('PA-19 penanda one_way hanya untuk tipe bool', function () {
    new SettingDefinition('approval.contoh', SettingType::Int, 1, 'Contoh', oneWay: true);
})->throws(InvalidArgumentException::class);

test('tidak ada kunci yang tampak seperti secret di registri (SEC-060)', function () {
    foreach (array_keys(SettingRegistry::all()) as $key) {
        expect($key)->not->toMatch('/password$|secret|api_key|smtp|token$/');
    }
});

test('PA-19 penanda locked & one_way sama dengan kolom Validasi 08 §2 (dua arah)', function () {
    foreach (documentedSettings() as $key => $doc) {
        $definition = SettingRegistry::get($key);

        expect($definition->locked)->toBe(str_contains($doc['validation'], '`locked`'), "Penanda locked {$key}")
            ->and($definition->oneWay)->toBe(str_contains($doc['validation'], '`one_way`'), "Penanda one_way {$key}");
    }
});

test('T-077 accounting.lock_date bertipe date, default kosong, terkunci, dan tidak publik', function () {
    $definition = SettingRegistry::get('accounting.lock_date');

    expect(SettingRegistry::GROUPS['accounting'] ?? null)->toBe('Akuntansi')
        ->and($definition->type)->toBe(SettingType::Date)
        ->and($definition->default)->toBeNull()
        ->and($definition->nullable)->toBeTrue()
        ->and($definition->locked)->toBeTrue()
        ->and($definition->isPublic)->toBeFalse()
        ->and(SettingRegistry::hasLocked('accounting'))->toBeTrue();
});
