<?php

use App\Domain\Inventory\Ledger\CostCalculator;
use App\Domain\Inventory\Models\Concerns\WrittenByLedgerOnly;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockMovement;

/*
 * SEC-102 / ADR-103 (T-077): saldo `stock_batches.qty_*` dan `stock_movements` hanya ditulis
 * `App\Domain\Inventory\Ledger`.
 *
 * Dua lapis:
 * 1. Pest arch: model buku stok memakai WrittenByLedgerOnly (save/update/insert/delete lewat model
 *    ditolak saat runtime, diuji di StockLedgerTest), CostCalculator hanya dipakai Ledger.
 * 2. Pemindaian token semua berkas app/ di luar Ledger, karena Pest arch hanya melihat pemakaian kelas,
 *    bukan query builder: `DB::table('stock_movements')->insert(...)`, SQL mentah `UPDATE stock_batches`,
 *    dan `StockBatch::query()->toBase()` (jalan pintas melewati builder baca-saja).
 * Larangan float di App\Domain\Inventory (ADR-101) sudah dijaga tests/Unit/Arch/NoFloatInDomainTest.php.
 */

const STOCK_LEDGER_TABLES = ['stock_movements', 'stock_batches'];

const STOCK_LEDGER_WRITE_METHODS = [
    'insert', 'insertgetid', 'insertorignore', 'insertorignorereturning', 'insertusing', 'insertorignoreusing', 'upsert',
    'update', 'updateorinsert', 'updatefrom', 'increment', 'decrement', 'incrementeach', 'decrementeach', 'delete',
    'forcedelete', 'truncate', 'create', 'forcecreate', 'save', 'fill', 'forcefill', 'updateorcreate', 'firstorcreate',
];

/**
 * Pelanggaran SEC-102 dalam satu sumber PHP. Dipisah per pernyataan (`;`, `{`, `}`) agar
 * `$receipt->update([...])` di Action yang sekadar membaca StockBatch tidak ikut tertangkap.
 *
 * @return list<string>
 */
function stockLedgerViolations(string $code): array
{
    $violations = [];
    $statement = [];
    $flush = function () use (&$statement, &$violations) {
        if ($statement === []) {
            return;
        }
        $mentionsTable = false;
        $mentionsModel = false;
        $writes = [];
        $escapes = false;
        foreach ($statement as [$id, $text, $line]) {
            if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
                $literal = trim($text, '\'"');
                if (in_array(strtolower($literal), STOCK_LEDGER_TABLES, true)) {
                    $mentionsTable = true;
                }
                if (preg_match('/\b(insert\s+(ignore\s+)?into|update|delete\s+from|replace\s+into|truncate(\s+table)?)\s+`?(stock_movements|stock_batches)\b/i', $literal) === 1) {
                    $violations[] = "baris {$line}: SQL mentah menulis tabel buku stok";
                }
            }
            if ($id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED) {
                $name = strtolower(ltrim((string) strrchr('\\'.$text, '\\'), '\\'));
                if (in_array($name, ['stockmovement', 'stockbatch'], true)) {
                    $mentionsModel = true;
                }
                if (in_array($name, STOCK_LEDGER_WRITE_METHODS, true)) {
                    $writes[] = $line;
                }
                if (in_array($name, ['tobase', 'getquery'], true)) {
                    $escapes = true;
                }
            }
        }
        $line = $statement[0][2];
        if ($mentionsTable && $writes !== []) {
            $violations[] = "baris {$line}: query builder menulis tabel buku stok";
        }
        if ($mentionsModel && $escapes) {
            $violations[] = "baris {$line}: model buku stok lewat toBase()/getQuery()";
        }
        $statement = [];
    };

    foreach (PhpToken::tokenize($code) as $token) {
        if ($token->text === ';' || $token->text === '{' || $token->text === '}') {
            $flush();

            continue;
        }
        if ($token->id === T_WHITESPACE || $token->id === T_COMMENT || $token->id === T_DOC_COMMENT) {
            continue;
        }
        $statement[] = [$token->id, $token->text, $token->line];
    }
    $flush();

    return $violations;
}

/** @return iterable<string, string> path relatif => isi */
function stockLedgerAppSources(string $subdir = ''): iterable
{
    $root = dirname(__DIR__, 2).'/app';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.$subdir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() === 'php') {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            yield $relative => (string) file_get_contents($file->getPathname());
        }
    }
}

arch('SEC-102 model buku stok baca-saja di luar StockLedger')
    ->expect([StockBatch::class, StockMovement::class])
    ->toUseTrait(WrittenByLedgerOnly::class);

arch('BR-159 CostCalculator hanya dipakai di dalam Ledger')
    ->expect(CostCalculator::class)
    ->toOnlyBeUsedIn('App\Domain\Inventory\Ledger');

test('SEC-102 tidak ada kode di luar App\Domain\Inventory\Ledger yang menulis stock_movements / stock_batches', function () {
    $violations = [];
    $scanned = 0;
    foreach (stockLedgerAppSources() as $path => $code) {
        if (str_starts_with($path, 'Domain/Inventory/Ledger/')) {
            continue;
        }
        $scanned++;
        foreach (stockLedgerViolations($code) as $v) {
            $violations[] = "{$path} {$v}";
        }
    }

    expect($scanned)->toBeGreaterThan(100)->and($violations)->toBe([]);
});

test('SEC-102 pemindai menangkap pelanggar (fixture) dan tidak salah tangkap kode baca', function (string $code, int $expected) {
    expect(stockLedgerViolations("<?php\n".$code))->toHaveCount($expected);
})->with([
    'DB::table insert' => ["DB::table('stock_movements')->insert(['qty' => 1]);", 1],
    'DB::table update qty' => ["DB::table(\"stock_batches\")->where('id', 1)->update(['qty_on_hand' => 0]);", 1],
    'increment' => ["DB::table('stock_batches')->increment('qty_reserved');", 1],
    'SQL mentah' => ["DB::statement('UPDATE stock_batches SET qty_on_hand = 0');", 1],
    'SQL mentah insert' => ['DB::insert("INSERT INTO stock_movements (qty) VALUES (1)");', 1],
    'toBase' => ['StockBatch::query()->toBase()->update(["qty_on_hand" => 0]);', 1],
    'baca saja' => ["DB::table('stock_batches')->where('product_id', 1)->sum('qty_on_hand');", 0],
    'baca model + tulis lain' => ["\$batch = StockBatch::query()->find(1); \$receipt->update(['stock_batch_id' => \$batch->id]);", 0],
    'nama tabel lain' => ["DB::table('stock_transfers')->update(['status' => 'sent']);", 0],
]);
