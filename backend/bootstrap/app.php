<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Api\ApiError;
use App\Support\Api\RequestId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Paling awal: semua log & galat setelah titik ini membawa request_id (NFR-011).
        $middleware->prepend(AssignRequestId::class);
        // Global (web & API), termasuk respons galat (SEC-063).
        $middleware->append(SecurityHeaders::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Galat domain yang disengaja (mis. di luar radius) bukan insiden; jangan memenuhi log error.
        $exceptions->dontReport(ApiError::class);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // API: format 05 §2.2 tanpa detail internal (SEC-061).
        $exceptions->render(new ApiExceptionRenderer);

        // Web: halaman galat generik saat debug mati (SEC-061). Saat debug nyala, halaman
        // developer Laravel tetap dipakai agar galat mudah dilacak di lokal.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if (ApiExceptionRenderer::appliesTo($request)) {
                return $response;
            }

            $status = $response->getStatusCode();

            // 419: token CSRF/sesi kedaluwarsa. Kembali ke form dengan pesan, bukan halaman galat.
            if ($status === 419) {
                Inertia::flash('toast', ['type' => 'error', 'message' => 'Sesi berakhir. Silakan coba lagi.']);

                return back();
            }

            if (! config('app.debug') && in_array($status, [403, 404, 500, 503], true)) {
                return Inertia::render('error', [
                    'status' => $status,
                    'requestId' => RequestId::current(),
                ])->toResponse($request)->setStatusCode($status);
            }

            return $response;
        });
    })->create();
