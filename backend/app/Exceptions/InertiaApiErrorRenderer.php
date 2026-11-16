<?php

namespace App\Exceptions;

use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Satu jalur galat domain untuk rute web (PA-16, 15 §11.3). Tanpa ini `ApiError` yang dilempar
 * Action dari controller Inertia jatuh menjadi halaman 500, dan tiap task Paket A akan membuat
 * penangkapnya sendiri.
 *
 * - `api/*`: tidak disentuh (ApiExceptionRenderer, 05 §2.2).
 * - Web yang meminta JSON (`expectsJson`, mis. endpoint pencarian di admin): format 05 §2.2.
 * - `NOT_FOUND` / `FORBIDDEN`: halaman galat 404/403 seperti `abort()` (SEC-033: di luar scope = 404).
 *   Kode lain berstatus 403 (mis. `APPROVAL_SELF_DECISION`) adalah aturan bisnis yang perlu
 *   dijelaskan di form, jadi ikut jalur di bawah.
 * - Sisanya: kembali ke halaman asal dengan galat validasi + toast. Teksnya selalu pesan kode
 *   (`publicMessage()`), tidak pernah pesan exception (SEC-061).
 */
final class InertiaApiErrorRenderer
{
    /** Kunci galat umum bila `details` tidak berisi galat per field. */
    public const FORM_KEY = 'form';

    public function __invoke(ApiError $e, Request $request): ?Response
    {
        if (ApiExceptionRenderer::appliesTo($request)) {
            return null;
        }

        if ($request->expectsJson()) {
            return (new ApiExceptionRenderer)->toJson($e);
        }

        $http = match ($e->errorCode) {
            ErrorCode::NOT_FOUND => new NotFoundHttpException(previous: $e),
            ErrorCode::FORBIDDEN => new AccessDeniedHttpException($e->publicMessage(), $e),
            default => null,
        };
        if ($http !== null) {
            // Dirender ulang lewat handler agar halaman galat (error.tsx / halaman debug) sama persis
            // dengan abort(404/403) yang dipakai controller lain.
            return app(ExceptionHandler::class)->render($request, $http);
        }

        $message = $e->publicMessage();
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return $this->back($request)->withErrors(self::fieldErrors($e) ?: [self::FORM_KEY => $message]);
    }

    /**
     * `details` berbentuk `field => pesan` (atau daftar pesan) menjadi galat field. Nilai lain
     * (angka, nomor dokumen dalam array bersarang) bukan pesan, jadi diabaikan.
     *
     * @return array<string, string|list<string>>
     */
    private static function fieldErrors(ApiError $e): array
    {
        $errors = [];
        foreach ($e->details as $field => $value) {
            if (is_string($value) && $value !== '') {
                $errors[$field] = $value;
            } elseif (is_array($value) && $value !== [] && array_is_list($value) && array_filter($value, 'is_string') === $value) {
                $errors[$field] = $value;
            }
        }

        return $errors;
    }

    /**
     * GET yang gagal lalu kembali ke URL yang sama akan berputar tanpa henti, jadi diarahkan ke dasbor.
     */
    private function back(Request $request): RedirectResponse
    {
        $previous = url()->previous();
        if ($request->isMethod('GET') && $previous === $request->fullUrl()) {
            return redirect()->route('admin.dashboard');
        }

        return back(fallback: route('admin.dashboard'));
    }
}
