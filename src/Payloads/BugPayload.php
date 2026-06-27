<?php

namespace BugCollector\Client\Payloads;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class BugPayload
{
    /**
     * Construit le payload d'erreur conforme au contrat /api/laravel-bugs/add.
     *
     * @return array<string, mixed>
     */
    public static function fromThrowable(Throwable $e, ?Request $request, int $traceDepth = 50): array
    {
        $message = substr($e->getMessage(), 0, 1000);

        return [
            'message'    => $message !== '' ? $message : $e::class,
            'type'       => $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500,
            'file'       => substr($e->getFile(), 0, 1000),
            'line'       => $e->getLine(),
            'stackTrace' => self::trace($e, $traceDepth),
            'url'        => $request ? substr($request->fullUrl(), 0, 2000) : 'console',
            'ip'         => ($request?->ip()) ?: '127.0.0.1',
            'userAgent'  => $request ? substr((string) $request->userAgent(), 0, 1000) : null,
            'username'   => self::userAttr($request, 'name'),
            'userEmail'  => self::userAttr($request, 'email'),
            'cookies'    => $request ? $request->cookies->all() : null,
        ];
    }

    /**
     * Frames file/line/function/class uniquement — les args sont volontairement
     * exclus (volume + données sensibles).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function trace(Throwable $e, int $depth): array
    {
        $frames = array_slice($e->getTrace(), 0, max(0, $depth));

        return array_map(static fn (array $frame): array => [
            'file'     => $frame['file'] ?? null,
            'line'     => $frame['line'] ?? null,
            'function' => $frame['function'] ?? null,
            'class'    => $frame['class'] ?? null,
        ], $frames);
    }

    private static function userAttr(?Request $request, string $attr): ?string
    {
        try {
            $user = $request?->user();
            $value = $user?->{$attr} ?? null;

            return $value === null ? null : substr((string) $value, 0, 255);
        } catch (Throwable) {
            return null;
        }
    }
}
