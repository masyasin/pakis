<?php

namespace App\Exceptions;

use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Api\RequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Mengubah exception di route API menjadi format galat 05 §2.2.
 *
 * Prinsip SEC-061: pesan exception, nama kelas, SQL, dan trace TIDAK PERNAH dikirim ke klien,
 * termasuk saat APP_DEBUG=true (staging yang salah setel tetap aman). Developer melacak detail
 * lewat log dengan request_id yang sama.
 */
final class ApiExceptionRenderer
{
    public static function appliesTo(Request $request): bool
    {
        return $request->is('api/*');
    }

    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::appliesTo($request)) {
            return null;
        }

        [$code, $details, $message, $headers] = $this->resolve($e);

        return new JsonResponse([
            'error' => [
                'code' => $code->value,
                'message' => $message ?? $code->message(),
                'details' => (object) $details,
                'request_id' => RequestId::current(),
            ],
        ], $code->status(), $headers);
    }

    /**
     * @return array{0: ErrorCode, 1: array<string, mixed>, 2: ?string, 3: array<string, string>}
     */
    private function resolve(Throwable $e): array
    {
        return match (true) {
            $e instanceof ApiError => [$e->errorCode, $e->details, $e->publicMessage(), []],
            $e instanceof ValidationException => [ErrorCode::VALIDATION_FAILED, $e->errors(), null, []],
            $e instanceof AuthenticationException => [ErrorCode::UNAUTHENTICATED, [], null, []],
            $e instanceof AuthorizationException => [ErrorCode::FORBIDDEN, [], null, []],
            // SEC-033: tidak ada dan di luar scope sama-sama NOT_FOUND, tanpa menyebut model/ID.
            $e instanceof ModelNotFoundException => [ErrorCode::NOT_FOUND, [], null, []],
            $e instanceof ThrottleRequestsException => $this->rateLimited($e),
            $e instanceof PostTooLargeException => [ErrorCode::PAYLOAD_TOO_LARGE, [], null, []],
            $e instanceof HttpExceptionInterface => [$this->fromStatus($e->getStatusCode()), [], null, $this->safeHeaders($e)],
            default => [ErrorCode::SERVER_ERROR, [], null, []],
        };
    }

    /**
     * @return array{0: ErrorCode, 1: array<string, mixed>, 2: null, 3: array<string, string>}
     */
    private function rateLimited(ThrottleRequestsException $e): array
    {
        $retryAfter = (int) ($e->getHeaders()['Retry-After'] ?? 60);

        return [ErrorCode::RATE_LIMITED, ['retry_after_s' => $retryAfter], null, ['Retry-After' => (string) $retryAfter]];
    }

    private function fromStatus(int $status): ErrorCode
    {
        return match ($status) {
            400 => ErrorCode::BAD_REQUEST,
            401 => ErrorCode::UNAUTHENTICATED,
            403 => ErrorCode::FORBIDDEN,
            404 => ErrorCode::NOT_FOUND,
            405 => ErrorCode::METHOD_NOT_ALLOWED,
            409 => ErrorCode::STATE_CONFLICT,
            413 => ErrorCode::PAYLOAD_TOO_LARGE,
            422 => ErrorCode::VALIDATION_FAILED,
            429 => ErrorCode::RATE_LIMITED,
            503 => ErrorCode::SERVICE_UNAVAILABLE,
            default => ErrorCode::SERVER_ERROR,
        };
    }

    /**
     * Hanya header yang memang berguna bagi klien (Allow untuk 405, Retry-After untuk 503).
     *
     * @return array<string, string>
     */
    private function safeHeaders(HttpExceptionInterface $e): array
    {
        return collect($e->getHeaders())
            ->only(['Allow', 'Retry-After'])
            ->map(fn ($value) => is_array($value) ? implode(', ', $value) : (string) $value)
            ->all();
    }
}
