<?php

use App\Domain\Access\Enums\DataScope as Scope;
use App\Domain\Access\Models\Role;
use App\Domain\Customer\Models\CustomerContact;
use App\Domain\Customer\Models\CustomerVerification;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Models\DisposalReport;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Models\StockOpnameLine;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptLine;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use Carbon\CarbonImmutable;
use Database\Seeders\ApprovalRuleSeeder;
use Database\Seeders\TransitStockLocationSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Tests\Support\AssertsIdor;
use Tests\Support\CustomerWorld;
use Tests\Support\EmployeeWorld;
use Tests\Support\LedgerWorld;
use Tests\Support\StockWorld;

/*
 * T-087 — sapu IDOR Paket A (06 §5, 15 §10, CLAUDE.md §6 "resource di luar scope → 404"). Daftar
 * route DIAMBIL DARI ROUTER: setiap route `admin.*` yang mengikat model domain Inventory, Purchasing,
 * Customer, atau Fleet lewat parameter controller ikut tersapu otomatis. Route baru dengan parameter
 * yang belum punya fixture di sini membuat test merah — tambahkan fixture-nya, jangan dikecualikan.
 *
 * Aktor: semua izin, tetapi scope `location` di lokasi gudang. Semua data milik lokasi lain (L-LAIN),
 * sehingga setiap route WAJIB 404 (bukan 403/422/200) — keberadaan data pun tidak boleh bocor.
 */

uses(AssertsIdor::class);

/** Domain model ber-scope Paket A (dimensi Stok & Pelanggan). */
const PAKET_A_SCOPED_NAMESPACES = ['App\\Domain\\Inventory\\', 'App\\Domain\\Purchasing\\', 'App\\Domain\\Customer\\', 'App\\Domain\\Fleet\\'];

/**
 * Route yang mengikat model Paket A tetapi memang TIDAK ber-scope, dengan alasannya. Daftar ini
 * sengaja pendek; menambah entri = keputusan desain yang harus tercatat di 06/15.
 */
const PAKET_A_UNSCOPED = [
    // Master area penjualan dikelola `sales_areas.manage` untuk seluruh perusahaan (15 §6.2: area = master, bukan data kios).
    'admin.sales-areas.update' => 'master area tanpa scope (15 §6.2)',
    // Pemasok & prinsipal = master pembelian global (15 §6.2 tanpa dimensi; rekening dijaga SEC-108).
    'admin.suppliers.show' => 'master pemasok global',
    'admin.suppliers.edit' => 'master pemasok global',
    'admin.suppliers.update' => 'master pemasok global',
    'admin.suppliers.reveal-account' => 'master pemasok global (SEC-108 izin suppliers.manage + audit)',
    'admin.principals.update' => 'master prinsipal global',
];

/** @return list<Route> */
function paketAScopedRoutes(): array
{
    $routes = [];
    foreach (Router::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();
        if (! str_starts_with($name, 'admin.') || isset(PAKET_A_UNSCOPED[$name]) || $route->parameterNames() === []) {
            continue;
        }
        $controller = $route->getControllerClass();
        $method = $route->getActionMethod();
        if ($controller === null || ! method_exists($controller, $method)) {
            continue;
        }
        foreach ((new ReflectionMethod($controller, $method))->getParameters() as $param) {
            $type = $param->getType();
            $class = $type instanceof ReflectionNamedType && ! $type->isBuiltin() ? $type->getName() : null;
            if ($class !== null && is_subclass_of($class, Model::class) && in_array($param->getName(), $route->parameterNames(), true)
                && array_filter(PAKET_A_SCOPED_NAMESPACES, fn ($ns) => str_starts_with($class, $ns)) !== []) {
                $routes[] = $route;
                break;
            }
        }
    }

    return $routes;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 03:00:00', 'UTC'));
    Notification::fake();
    $this->org = EmployeeWorld::make($this);
    $this->seed([TransitStockLocationSeeder::class, ApprovalRuleSeeder::class]);
    $this->useRealPermissions();
    CustomerWorld::regions();

    // Aktor: semua izin, scope lokasi = gudang saja.
    $role = Role::query()->create(['name' => 'uji_idor_paket_a', 'label' => 'Uji IDOR', 'guard_name' => 'web', 'data_scope' => Scope::Location]);
    $role->givePermissionTo(Permission::all());
    $this->actor = $this->org->actor('uji_idor_paket_a', $this->org->employee($this->org->gudang));
    $mine = StockWorld::stockLocation($this->org->gudang, 'GD-SAYA');
    $mine2 = StockWorld::stockLocation($this->org->gudang, 'TK-SAYA', 'store');

    // Semua data di lokasi lain.
    $far = StockWorld::location('L-LAIN');
    $farStock = StockWorld::stockLocation($far, 'GD-LAIN');
    $farStock2 = StockWorld::stockLocation($far, 'GD-LAIN2', 'store');
    $bin = LedgerWorld::bin($farStock, 'R-09');
    $product = LedgerWorld::product('IDOR-01');
    $batch = LedgerWorld::receive($farStock, $product, '10', '1000', new BatchKey('B-IDOR', '2027-12-31', $bin->id));
    $supplier = tap((new Supplier)->forceFill(['code' => 'SUP-IDOR', 'name' => 'Supplier IDOR']))->save();
    $reason = fn (ReferenceGroup $g) => (int) ReferenceItem::query()->forGroup($g)->value('id');

    $transfer = tap((new StockTransfer)->forceFill(['number' => 'MUT-IDOR', 'from_stock_location_id' => $farStock->id, 'to_stock_location_id' => $farStock2->id, 'status' => 'draft']))->save();
    $opname = tap((new StockOpname)->forceFill(['number' => 'OPN-IDOR', 'stock_location_id' => $farStock->id, 'scope' => 'full', 'blind_count' => false, 'status' => 'counting', 'frozen_at' => now()]))->save();
    $opnameLine = tap((new StockOpnameLine)->forceFill(['stock_opname_id' => $opname->id, 'stock_batch_id' => $batch->id, 'product_id' => $product->id, 'batch_no' => 'B-IDOR', 'qty_system_base' => '10', 'photo_path' => 'opname/idor.jpg']))->save();
    $report = tap((new DisposalReport)->forceFill(['number' => 'BAP-IDOR', 'stock_location_id' => $farStock->id, 'reason_id' => $reason(ReferenceGroup::DisposalReason),
        'method' => 'destroy', 'status' => 'draft', 'document_path' => 'disposal/idor.pdf', 'photo_paths' => ['disposal/idor.jpg']]))->save();
    $po = tap((new PurchaseOrder)->forceFill(['number' => 'PO-IDOR', 'supplier_id' => $supplier->id, 'stock_location_id' => $farStock->id, 'order_date' => '2026-11-17', 'payment_term_days' => 30]))->save();
    $gr = tap((new GoodsReceipt)->forceFill(['number' => 'GR-IDOR', 'supplier_id' => $supplier->id, 'stock_location_id' => $farStock->id, 'received_at' => now()]))->save();
    $grLine = tap((new GoodsReceiptLine)->forceFill(['goods_receipt_id' => $gr->id, 'product_id' => $product->id, 'product_unit_id' => $product->units()->value('id'),
        'qty_received' => '1', 'qty_received_base' => '1', 'batch_no' => 'B-IDOR', 'unit_cost' => '1000']))->save();
    $ret = tap((new PurchaseReturn)->forceFill(['number' => 'RTB-IDOR', 'supplier_id' => $supplier->id, 'stock_location_id' => $farStock->id, 'reason_id' => $reason(ReferenceGroup::PurchaseReturnReason)]))->save();
    $vehicle = StockWorld::vehicle('K 1 IDOR', $far);
    $area = CustomerWorld::area('AR-IDOR', ['home_stock_location_id' => $farStock->id]);
    $customer = CustomerWorld::activeCustomer($area, 'Toko IDOR', ['home_stock_location_id' => $farStock->id]);
    $contact = tap((new CustomerContact)->forceFill(['customer_id' => $customer->id, 'name' => 'Kontak IDOR']))->save();
    $verification = tap((new CustomerVerification)->forceFill(['customer_id' => $customer->id, 'type' => 'activation', 'method' => 'office_call', 'office_call_note' => 'Catatan uji',
        'phone_target' => $customer->owner_phone, 'submitted_by' => $this->actor->id, 'photo_path' => 'verifikasi/idor.jpg']))->save();

    // Body minimum agar validasi (termasuk lokasi pilihan dalam scope aktor) lolos, sehingga yang
    // menentukan adalah pemeriksaan scope dokumen: 404, bukan 422 yang membocorkan keberadaannya.
    $unit = (int) $product->units()->value('id');
    $jpeg = function (): UploadedFile {
        $image = imagecreatetruecolor(16, 16);
        ob_start();
        imagejpeg($image);

        return UploadedFile::fake()->createWithContent('foto.jpg', (string) ob_get_clean());
    };
    $this->payload = fn (string $route): array => match ($route) {
        'admin.purchase-orders.update' => ['supplier_id' => $supplier->id, 'stock_location_id' => $mine->id, 'order_date' => '2026-11-17',
            'lines' => [['product_unit_id' => $unit, 'qty' => '1', 'unit_price' => '1000']]],
        'admin.purchase-orders.close' => ['reason' => 'Uji sapu IDOR'],
        'admin.goods-receipts.update' => ['supplier_id' => $supplier->id, 'stock_location_id' => $mine->id, 'received_at' => '2026-11-17T09:00',
            'supplier_delivery_no' => 'SJ-IDOR', 'lines' => [['product_unit_id' => $unit, 'qty_received' => '1', 'unit_cost' => '1000', 'batch_no' => 'B-IDOR']]],
        'admin.goods-receipts.serials' => ['serials' => [], 'method' => 'scan'],
        'admin.purchase-returns.update' => ['supplier_id' => $supplier->id, 'stock_location_id' => $mine->id, 'reason_id' => $reason(ReferenceGroup::PurchaseReturnReason),
            'lines' => [['stock_batch_id' => $batch->id, 'qty_base' => '1']]],
        'admin.stock-transfers.update' => ['from_stock_location_id' => $mine->id, 'to_stock_location_id' => $mine2->id, 'vehicle_id' => null, 'note' => null,
            'lines' => [['product_id' => $product->id, 'qty_base' => '1', 'source_batch_id' => null]]],
        'admin.stock-transfers.receive' => ['lines' => [['line_id' => 1, 'qty_received' => '1']]],
        'admin.stock-opnames.lines.store' => ['product_id' => $product->id, 'batch_no' => 'B-BARU', 'qty' => '1'],
        'admin.stock-opnames.lines.count' => ['mode' => 'count', 'qty' => '1'],
        'admin.stock.release' => ['qty_base' => '1', 'target_bin_id' => $bin->id, 'reason' => 'Uji sapu IDOR'],
        'admin.disposal-reports.execute' => ['executed_location' => 'Halaman gudang', 'witnesses' => [
            ['name' => 'Saksi Satu', 'role' => 'Staf', 'organization' => 'Dinas'], ['name' => 'Saksi Dua', 'role' => 'Staf', 'organization' => 'Prinsipal']],
            'document' => UploadedFile::fake()->createWithContent('ba.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"), 'photos' => [$jpeg()]],
        default => [],
    };

    // Nilai parameter route → id data di luar scope. `{line}` dipakai opname & penerimaan.
    $this->params = fn (string $route) => [
        'transfer' => $transfer->id, 'opname' => $opname->id, 'report' => $report->id, 'index' => 0, 'batch' => $batch->id,
        'po' => $po->id, 'gr' => $gr->id, 'ret' => $ret->id, 'stockLocation' => $farStock->id, 'bin' => $bin->id,
        'vehicle' => $vehicle->id, 'customer' => $customer->id, 'contact' => $contact->id, 'verification' => $verification->id,
        'line' => str_starts_with($route, 'admin.stock-opnames.') ? $opnameLine->id : $grLine->id,
    ];
});

test('06 §5 sapu IDOR Paket A: router memuat route ber-scope (daftar tidak kosong, termasuk route T-082–T-087)', function () {
    $names = array_map(fn (Route $r) => $r->getName(), paketAScopedRoutes());

    // Batas bawah, bukan angka tetap: refleksi yang rusak tidak boleh membuat sapu kosong lolos.
    expect(count($names))->toBeGreaterThan(50)->and($names)->toContain('admin.stock-transfers.show', 'admin.stock-opnames.lines.count', 'admin.disposal-reports.execute',
        'admin.stock.release', 'admin.goods-receipts.post', 'admin.purchase-returns.ship', 'admin.customers.merge',
        'admin.stock-opnames.lines.photo', 'admin.disposal-reports.document', 'admin.vehicles.update', 'admin.stock-bins.update');
});

test('06 §5 SEC-033 setiap route show/edit/update/aksi Paket A untuk data di luar scope → 404', function () {
    $failures = [];
    foreach (paketAScopedRoutes() as $route) {
        $name = (string) $route->getName();
        $values = ($this->params)($name);
        $missing = array_diff($route->parameterNames(), array_keys($values));
        if ($missing !== []) {
            $failures[] = "{$name}: parameter tanpa fixture [".implode(', ', $missing).']';

            continue;
        }
        $bound = array_intersect_key($values, array_flip($route->parameterNames()));
        // Route bertanda tangan butuh URL sah; selain itu tanda tangan yang menolak (403) lebih dulu.
        $uri = in_array('signed:relative', $route->gatherMiddleware(), true)
            ? URL::temporarySignedRoute($name, now()->addMinutes(5), $bound, false)
            : route($name, $bound, false);
        $method = $route->methods()[0];
        $payload = ($this->payload)($name);
        $client = $this->actingAs($this->actor)->withSession(['auth.password_confirmed_at' => time()]);
        // Berkas unggahan tidak bisa lewat JSON: post() biasa (multipart) dengan Accept JSON.
        $status = isset($payload['document'])
            ? $client->withHeader('Accept', 'application/json')->post($uri, $payload)->status()
            : $client->json($method, $uri, $payload)->status();
        if ($status !== 404) {
            $failures[] = "{$name} {$method} {$uri} → {$status}";
        }
    }

    expect($failures)->toBe([]);
});
