<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerVerification;
use App\Http\Controllers\Controller;
use App\Support\Upload\FileKind;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Berkas privat kios (SEC-108, SEC-109, T-074; pola selfie T-038 / berkas izin edar T-071): foto toko,
 * foto verifikasi, dan dokumen kelayakan pembeli terbatas. Disajikan dari disk privat lewat URL
 * bertanda tangan berumur pendek (`signed:relative` + `customers.view` + binding ber-scope), tidak
 * pernah lewat disk publik. Tautan dibuat `CustomerFileUrls`.
 */
class CustomerFileController extends Controller
{
    public function photo(Customer $customer): StreamedResponse
    {
        Gate::authorize('view', $customer);

        return self::stream($customer->photo_path, "toko-{$customer->id}");
    }

    public function restrictedDocument(Customer $customer): StreamedResponse
    {
        Gate::authorize('view', $customer);

        return self::stream($customer->restricted_buyer_document_path, "pembeli-terbatas-{$customer->id}");
    }

    public function verificationPhoto(CustomerVerification $verification): StreamedResponse
    {
        abort_unless($verification->customer !== null, 404);
        Gate::authorize('view', $verification->customer);

        return self::stream($verification->photo_path, "verifikasi-{$verification->id}");
    }

    private static function stream(?string $path, string $name): StreamedResponse
    {
        $disk = Storage::disk(CustomerVerification::DISK);
        abort_unless($path !== null && $disk->exists($path), 404);
        $kind = FileKind::tryFrom(pathinfo($path, PATHINFO_EXTENSION));
        abort_if($kind === null, 404);

        return $disk->response($path, "{$name}.{$kind->value}", [
            'Content-Type' => $kind->mime(),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
