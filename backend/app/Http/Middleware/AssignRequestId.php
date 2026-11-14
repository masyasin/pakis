<?php

namespace App\Http\Middleware;

use App\Support\Api\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memberi setiap request ID ULID (NFR-011). Didaftarkan paling awal di middleware global
 * agar galat dari middleware lain pun membawa ID yang sama di log dan respons.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = RequestId::fromClient($request->headers->get(RequestId::HEADER)) ?? (string) Str::ulid();

        RequestId::assign($id);
        $request->headers->set(RequestId::HEADER, $id);

        $response = $next($request);
        $response->headers->set(RequestId::HEADER, $id);

        return $response;
    }
}
