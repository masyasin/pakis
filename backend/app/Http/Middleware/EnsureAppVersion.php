<?php

namespace App\Http\Middleware;

use App\Domain\Device\Support\SemVer;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BR-027: Pakis Pro di bawah `mobile.min_version` (atau tanpa header `X-App-Version` yang sah)
 * menerima 426 di semua endpoint API kecuali `GET /config` (route itu tidak memakai middleware ini).
 */
class EnsureAppVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $minimum = (string) setting('mobile.min_version');

        if (SemVer::lessThan($request->header('X-App-Version'), $minimum)) {
            $download = (string) setting('mobile.download_url');

            throw new ApiError(ErrorCode::APP_UPDATE_REQUIRED, [
                'min_version' => $minimum,
                'download_url' => $download === '' ? null : $download,
            ]);
        }

        return $next($request);
    }
}
