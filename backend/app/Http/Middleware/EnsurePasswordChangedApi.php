<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-006 untuk API (05 §2.3 `PASSWORD_CHANGE_REQUIRED`): akun wajib ganti password hanya boleh
 * memanggil `PUT /me/password`, `GET /me`, dan `POST /auth/logout` (`GET /config` publik).
 */
class EnsurePasswordChangedApi
{
    /** Nama route yang tetap boleh dipanggil. */
    public const ALLOWED = ['api.v1.me.password', 'api.v1.me.show', 'api.v1.auth.logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->must_change_password && ! $request->routeIs(...self::ALLOWED)) {
            throw new ApiError(ErrorCode::PASSWORD_CHANGE_REQUIRED);
        }

        return $next($request);
    }
}
