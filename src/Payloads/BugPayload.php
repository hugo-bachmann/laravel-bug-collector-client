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
     * @param  array<int, string>  $redactCookieNames  Noms de cookies à masquer (en plus du préfixe remember_).
     * @return array<string, mixed>
     */
    public static function fromThrowable(
        Throwable $e,
        ?Request $request,
        int $traceDepth = 50,
        bool $includeCookies = false,
        array $redactCookieNames = [],
    ): array {
        $message = substr($e->getMessage(), 0, 1000);

        if ($request !== null && $includeCookies) {
            $cookies = self::redactCookies($request->cookies->all(), $redactCookieNames);
        } else {
            $cookies = null;
        }

        return [
            'message' => $message !== '' ? $message : $e::class,
            'type' => $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500,
            'file' => substr($e->getFile(), 0, 1000),
            'line' => $e->getLine(),
            'stackTrace' => self::trace($e, $traceDepth),
            'url' => $request ? substr($request->fullUrl(), 0, 2000) : 'console',
            'ip' => ($request?->ip()) ?: '127.0.0.1',
            'userAgent' => ($ua = $request?->userAgent()) !== null ? substr($ua, 0, 1000) : null,
            'username' => self::userAttr($request, 'name'),
            'userEmail' => self::userAttr($request, 'email'),
            'cookies' => $cookies,
        ];
    }

    /**
     * Retourne une copie du tableau de cookies où les entrées sensibles sont masquées.
     * Sont masqués : les noms listés dans $redactNames et ceux commençant par remember_.
     *
     * @param  array<string, mixed>  $cookies
     * @param  array<int, string>  $redactNames
     * @return array<string, mixed>
     */
    private static function redactCookies(array $cookies, array $redactNames): array
    {
        $result = [];

        foreach ($cookies as $key => $value) {
            if (in_array($key, $redactNames, strict: true) || str_starts_with($key, 'remember_')) {
                $result[$key] = '[REDACTED]';
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
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
            'file' => $frame['file'] ?? null,
            'line' => $frame['line'] ?? null,
            'function' => $frame['function'] ?? null,
            'class' => $frame['class'] ?? null,
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
