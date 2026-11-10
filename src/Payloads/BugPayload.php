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
        string $basePath = '',
    ): array {
        $message = mb_strcut($e->getMessage(), 0, 1000, 'UTF-8');

        if ($request !== null && $includeCookies) {
            $cookies = self::redactCookies($request->cookies->all(), $redactCookieNames);
        } else {
            $cookies = null;
        }

        return [
            'message' => $message !== '' ? $message : self::className($e),
            'type' => $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500,
            'class' => mb_strcut(self::className($e), 0, 255, 'UTF-8'),
            'previous' => self::previousChain($e, $basePath),
            'file' => mb_strcut(self::relativePath($e->getFile(), $basePath), 0, 1000, 'UTF-8'),
            'line' => $e->getLine(),
            'stackTrace' => self::trace($e, $traceDepth, $basePath),
            'url' => $request ? mb_strcut(self::redactUrl($request->fullUrl()), 0, 2000, 'UTF-8') : 'console',
            'ip' => ($request?->ip()) ?: '127.0.0.1',
            'userAgent' => ($ua = $request?->userAgent()) !== null ? mb_strcut($ua, 0, 1000, 'UTF-8') : null,
            'username' => self::userAttr($request, 'name'),
            'userEmail' => self::userAttr($request, 'email'),
            'cookies' => $cookies,
        ];
    }

    /** Fragments de noms (insensibles à la casse) toujours masqués dans les cookies. */
    private const SENSITIVE_COOKIE_FRAGMENTS = ['token', 'auth', 'session', 'csrf', 'secret', 'password'];

    /** Noms de paramètres d'URL (insensibles à la casse) dont la valeur est masquée. */
    private const SENSITIVE_QUERY_KEYS = [
        'token', '_token', 'api_token', 'access_token', 'refresh_token',
        'signature', 'password', 'passwd', 'pwd', 'secret',
        'api_key', 'apikey', 'key', 'code', 'auth', 'otp',
    ];

    /**
     * Retourne une copie du tableau de cookies où les entrées sensibles sont masquées.
     * Sont masqués : les noms listés dans $redactNames, ceux commençant par remember_,
     * et ceux contenant un fragment sensible (token, auth, session, csrf, secret, password).
     *
     * @param  array<string, mixed>  $cookies
     * @param  array<int, string>  $redactNames
     * @return array<string, mixed>
     */
    private static function redactCookies(array $cookies, array $redactNames): array
    {
        $result = [];

        foreach ($cookies as $key => $value) {
            $result[$key] = self::isSensitiveCookie((string) $key, $redactNames) ? '[REDACTED]' : $value;
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $redactNames
     */
    private static function isSensitiveCookie(string $key, array $redactNames): bool
    {
        if (in_array($key, $redactNames, strict: true) || str_starts_with($key, 'remember_')) {
            return true;
        }

        $lower = strtolower($key);

        foreach (self::SENSITIVE_COOKIE_FRAGMENTS as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Masque la valeur des paramètres de query sensibles (token, signature, password…).
     * L'URL sans query est retournée telle quelle.
     */
    private static function redactUrl(string $fullUrl): string
    {
        $parts = parse_url($fullUrl);

        if ($parts === false || ! isset($parts['query']) || $parts['query'] === '') {
            return $fullUrl;
        }

        parse_str($parts['query'], $params);

        $sensitive = array_map('strtolower', self::SENSITIVE_QUERY_KEYS);

        array_walk_recursive($params, static function (&$value, $key) use ($sensitive): void {
            if (in_array(strtolower((string) $key), $sensitive, strict: true)) {
                $value = '[REDACTED]';
            }
        });

        $rebuilt = http_build_query($params);

        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $scheme.$host.$port.$path.'?'.$rebuilt.$fragment;
    }

    /** Rend $path relatif à $basePath s'il en dépend, sinon le retourne tel quel. */
    public static function relativePath(string $path, string $basePath): string
    {
        $prefix = rtrim($basePath, '/').'/';

        if ($basePath === '' || ! str_starts_with($path, $prefix)) {
            return $path;
        }

        return substr($path, strlen($prefix));
    }

    /** Nom de classe sans le suffixe NUL + chemin absolu des classes anonymes. */
    private static function className(Throwable $e): string
    {
        $class = $e::class;

        return strstr($class, "\0", true) ?: $class;
    }

    /**
     * Chaîne des causes (getPrevious), plafonnée à 5 maillons (borne aussi les cycles).
     *
     * @return list<array{class: string, message: string, file: string, line: int}>
     */
    private static function previousChain(Throwable $e, string $basePath): array
    {
        $chain = [];

        for ($p = $e->getPrevious(); $p !== null && count($chain) < 5; $p = $p->getPrevious()) {
            $chain[] = [
                'class' => mb_strcut(self::className($p), 0, 255, 'UTF-8'),
                'message' => mb_strcut($p->getMessage(), 0, 1000, 'UTF-8'),
                'file' => mb_strcut(self::relativePath($p->getFile(), $basePath), 0, 1000, 'UTF-8'),
                'line' => $p->getLine(),
            ];
        }

        return $chain;
    }

    /**
     * Frames file/line/function/class uniquement — les args sont volontairement
     * exclus (volume + données sensibles).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function trace(Throwable $e, int $depth, string $basePath): array
    {
        $frames = array_slice($e->getTrace(), 0, max(0, $depth));

        return array_map(static fn (array $frame): array => [
            'file' => isset($frame['file']) ? self::relativePath($frame['file'], $basePath) : null,
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

            return $value === null ? null : mb_strcut((string) $value, 0, 255, 'UTF-8');
        } catch (Throwable) {
            return null;
        }
    }
}
