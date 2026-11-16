<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\RevokeRegistration;
use App\Domain\Catalog\Actions\SaveRegistration;
use App\Domain\Catalog\Enums\RegistrationStatus;
use App\Domain\Catalog\Listings\ProductRegistrationListing;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductRegistration;
use App\Domain\Catalog\Queries\ActiveRegistration;
use App\Domain\Catalog\Support\RegistrationState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductRegistrationRequest;
use App\Http\Requests\Catalog\RevokeRegistrationRequest;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use App\Support\Upload\FileKind;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Izin edar (15 §5 `admin.product-registrations.*`, FR-A10, BR-152, SEC-109). Lihat = `products.view`;
 * tambah/ubah/cabut = `product_registrations.manage` (di rute). Berkas hanya lewat signed URL pendek.
 */
class ProductRegistrationController extends Controller
{
    /** Umur tautan berkas izin (SEC-109, `15` §10.2: signed URL 5 menit). */
    private const DOCUMENT_URL_MINUTES = 5;

    #[UsesListing(ProductRegistrationListing::class)]
    public function index(Request $request): Response
    {
        $today = ActiveRegistration::localDate();
        $result = ListingQuery::apply(ProductRegistration::query()->with('product:id,sku,name'), new ProductRegistrationListing, $request)
            ->through(fn (ProductRegistration $r) => [
                ...self::row($r, $today),
                'product' => ['id' => $r->product->id, 'sku' => $r->product->sku, 'name' => $r->product->name],
            ]);

        return Inertia::render('product-registrations/index', [
            ...$result->toInertia(),
            'thresholdDays' => RegistrationState::thresholdDays(),
        ]);
    }

    public function store(ProductRegistrationRequest $request, Product $product, SaveRegistration $action): RedirectResponse
    {
        $saved = $action->handle($product, null, $request->validated(), self::uploadedDocument($request), $request->user()?->getAuthIdentifier());
        Inertia::flash('toast', ['type' => 'success', 'message' => "Izin {$saved->registration_no} ditambahkan."]);

        return to_route('admin.products.show', ['product' => $product, 'tab' => 'izin']);
    }

    public function update(ProductRegistrationRequest $request, Product $product, ProductRegistration $registration, SaveRegistration $action): RedirectResponse
    {
        abort_unless($registration->product_id === $product->id, 404);
        $saved = $action->handle($product, $registration, $request->validated(), self::uploadedDocument($request), $request->user()?->getAuthIdentifier());
        Inertia::flash('toast', ['type' => 'success', 'message' => "Izin {$saved->registration_no} disimpan."]);

        return to_route('admin.products.show', ['product' => $product, 'tab' => 'izin']);
    }

    public function revoke(RevokeRegistrationRequest $request, Product $product, ProductRegistration $registration, RevokeRegistration $action): RedirectResponse
    {
        abort_unless($registration->product_id === $product->id, 404);
        $action->handle($registration, (string) $request->validated('reason'), $request->user()?->getAuthIdentifier());
        Inertia::flash('toast', ['type' => 'success', 'message' => "Izin {$registration->registration_no} dicabut."]);

        return to_route('admin.products.show', ['product' => $product, 'tab' => 'izin']);
    }

    /** Berkas izin dari disk privat; rute memakai middleware `signed` + `can:products.view`. */
    public function document(ProductRegistration $registration): StreamedResponse
    {
        $path = $registration->document_path;
        $disk = Storage::disk(ProductRegistration::DOCUMENT_DISK);
        abort_unless($path !== null && $disk->exists($path), 404);
        $kind = FileKind::tryFrom(pathinfo($path, PATHINFO_EXTENSION));
        abort_if($kind === null, 404);

        return $disk->response($path, "izin-{$registration->id}.{$kind->value}", [
            'Content-Type' => $kind->mime(),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    /**
     * Baris izin untuk daftar & tab produk.
     *
     * @return array<string, mixed>
     */
    public static function row(ProductRegistration $r, string $today): array
    {
        $validUntil = $r->valid_until->toDateString();
        $active = $r->status === RegistrationStatus::Active && $validUntil >= $today;

        return [
            'id' => $r->id,
            'registration_no' => $r->registration_no,
            'holder_name' => $r->holder_name,
            'issued_at' => $r->issued_at?->toDateString(),
            'valid_until' => $validUntil,
            'days_left' => $active ? (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($validUntil)) : null,
            'status' => $r->status->value,
            'status_label' => $active || $r->status !== RegistrationStatus::Active ? $r->status->label() : RegistrationStatus::Expired->label(),
            'expiring' => $active && $validUntil <= RegistrationState::expiringUntil(),
            'note' => $r->note,
            'document_url' => $r->document_path !== null
                ? URL::temporarySignedRoute('admin.product-registrations.document', now()->addMinutes(self::DOCUMENT_URL_MINUTES), ['registration' => $r->id], false)
                : null,
        ];
    }

    private static function uploadedDocument(Request $request): ?UploadedFile
    {
        $file = $request->file('document');

        return $file instanceof UploadedFile ? $file : null;
    }
}
