<?php

declare(strict_types=1);

namespace jinx\web;

use RuntimeException;

require_once __DIR__ . '/WebNativeFunctionRegistry.generated.php';
require_once __DIR__ . '/WebNativeOracleDispatch.generated.php';

final class WebNativeFunctions
{
    /**
     * Worker-safe native PHP wrappers.
     *
     * These are intentionally pure/value functions. Do not add filesystem,
     * process, network, database, session, mail, eval, include/require, or
     * environment-mutating functions here.
     *
     * @return list<string>
     */
    public static function allowedNames(): array
    {
        return array_values(array_unique(array_merge(
            WebNativeFunctionRegistry::names(),
            self::phpCryptoFallbackNames()
        )));
    }

    /**
     * @return list<string>
     */
    private static function handWrappedNames(): array
    {
        return [
            // Type/value conversion
            'boolval',
            'floatval',
            'intval',
            'strval',

            // Type checks
            'is_array',
            'is_bool',
            'is_float',
            'is_int',
            'is_numeric',
            'is_string',
            'is_null',

            // String length/case/trim
            'strlen',
            'trim',
            'ltrim',
            'rtrim',
            'strtolower',
            'strtoupper',
            'ucfirst',
            'lcfirst',
            'ucwords',

            // String search/slice/replace
            'substr',
            'strpos',
            'strrpos',
            'str_contains',
            'str_starts_with',
            'str_ends_with',
            'str_replace',

            // String splitting/joining/escaping
            'explode',
            'implode',
            'htmlspecialchars',
            'htmlentities',
            'nl2br',

            // Regex value transforms
            'preg_match',
            'preg_replace',

            // JSON value transforms
            'json_encode',
            'json_decode',

            // Array/value helpers
            'count',
            'in_array',
            'array_key_exists',
            'array_values',
            'array_keys',
            'array_reverse',
            'array_unique',

            // Math
            'abs',
            'ceil',
            'floor',
            'max',
            'min',
            'round',
            'sqrt',
            'pow',
        ];
    }

    /**
     * Crypto-sensitive functions are correctness-first PHP fallbacks.
     *
     * They intentionally bypass the Oracle/native placeholder path until JINX
     * has exact native crypto implementations. This preserves original PHP
     * behavior for algorithms, password hashing, randomness, and extension
     * availability without pretending those calls are native ASM yet.
     *
     * @return list<string>
     */
    private static function phpCryptoFallbackNames(): array
    {
        return [
            'crc32',
            'crypt',
            'hash',
            'hash_algos',
            'hash_copy',
            'hash_equals',
            'hash_file',
            'hash_final',
            'hash_hkdf',
            'hash_hmac',
            'hash_hmac_algos',
            'hash_hmac_file',
            'hash_init',
            'hash_pbkdf2',
            'hash_update',
            'hash_update_file',
            'hash_update_stream',
            'md5',
            'md5_file',
            'openssl_decrypt',
            'openssl_digest',
            'openssl_encrypt',
            'openssl_random_pseudo_bytes',
            'password_get_info',
            'password_hash',
            'password_needs_rehash',
            'password_verify',
            'random_bytes',
            'random_int',
            'sha1',
            'sha1_file',
            'sodium_bin2hex',
            'sodium_hex2bin',
        ];
    }

    public static function isAllowed(string $name): bool
    {
        $name = strtolower($name);

        return in_array($name, self::phpCryptoFallbackNames(), true)
            || WebNativeFunctionRegistry::has($name);
    }

    /**
     * @param list<mixed> $args
     */
    public static function call(string $name, array $args): mixed
    {
        $name = strtolower($name);

        if (in_array($name, self::phpCryptoFallbackNames(), true)) {
            return self::callPhpCryptoFallback($name, $args);
        }

        $oracleHit = false;
        $oracleResult = WebNativeOracleDispatch::tryCallLowercase($name, $args, $oracleHit);

        if ($oracleHit) {
            return $oracleResult;
        }

        return match ($name) {
            // Type/value conversion
            'boolval' => boolval($args[0] ?? false),
            'floatval' => floatval($args[0] ?? 0),
            'intval' => intval($args[0] ?? 0),
            'strval' => strval($args[0] ?? ''),

            // Type checks
            'is_array' => is_array($args[0] ?? null),
            'is_bool' => is_bool($args[0] ?? null),
            'is_float' => is_float($args[0] ?? null),
            'is_int' => is_int($args[0] ?? null),
            'is_numeric' => is_numeric($args[0] ?? null),
            'is_string' => is_string($args[0] ?? null),
            'is_null' => is_null($args[0] ?? null),

            // String length/case/trim
            'strlen' => strlen((string) ($args[0] ?? '')),
            'trim' => trim((string) ($args[0] ?? ''), array_key_exists(1, $args) ? (string) $args[1] : " \n\r\t\v\0"),
            'ltrim' => ltrim((string) ($args[0] ?? ''), array_key_exists(1, $args) ? (string) $args[1] : " \n\r\t\v\0"),
            'rtrim' => rtrim((string) ($args[0] ?? ''), array_key_exists(1, $args) ? (string) $args[1] : " \n\r\t\v\0"),
            'strtolower' => strtolower((string) ($args[0] ?? '')),
            'strtoupper' => strtoupper((string) ($args[0] ?? '')),
            'ucfirst' => ucfirst((string) ($args[0] ?? '')),
            'lcfirst' => lcfirst((string) ($args[0] ?? '')),
            'ucwords' => ucwords((string) ($args[0] ?? '')),

            // String search/slice/replace
            'substr' => substr(
                (string) ($args[0] ?? ''),
                (int) ($args[1] ?? 0),
                array_key_exists(2, $args) ? (int) $args[2] : null
            ),
            'strpos' => strpos((string) ($args[0] ?? ''), (string) ($args[1] ?? ''), (int) ($args[2] ?? 0)),
            'strrpos' => strrpos((string) ($args[0] ?? ''), (string) ($args[1] ?? ''), (int) ($args[2] ?? 0)),
            'str_contains' => str_contains((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'str_starts_with' => str_starts_with((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'str_ends_with' => str_ends_with((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'str_replace' => self::callStrReplace($args),

            // String splitting/joining/escaping
            'explode' => explode((string) ($args[0] ?? ''), (string) ($args[1] ?? ''), array_key_exists(2, $args) ? (int) $args[2] : PHP_INT_MAX),
            'implode' => self::callImplode($args),
            'htmlspecialchars' => htmlspecialchars(
                (string) ($args[0] ?? ''),
                (int) ($args[1] ?? ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401),
                (string) ($args[2] ?? 'UTF-8'),
                (bool) ($args[3] ?? true)
            ),
            'htmlentities' => htmlentities(
                (string) ($args[0] ?? ''),
                (int) ($args[1] ?? ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401),
                (string) ($args[2] ?? 'UTF-8'),
                (bool) ($args[3] ?? true)
            ),
            'nl2br' => nl2br((string) ($args[0] ?? ''), (bool) ($args[1] ?? true)),

            // Regex value transforms
            'preg_match' => preg_match((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'preg_replace' => preg_replace(
                (string) ($args[0] ?? ''),
                (string) ($args[1] ?? ''),
                (string) ($args[2] ?? ''),
                (int) ($args[3] ?? -1)
            ),

            // JSON value transforms
            'json_encode' => json_encode($args[0] ?? null),
            'json_decode' => json_decode((string) ($args[0] ?? ''), (bool) ($args[1] ?? true)),

            // Array/value helpers
            'count' => count(is_countable($args[0] ?? null) ? $args[0] : []),
            'in_array' => in_array($args[0] ?? null, is_array($args[1] ?? null) ? $args[1] : [], (bool) ($args[2] ?? false)),
            'array_key_exists' => array_key_exists((string) ($args[0] ?? ''), is_array($args[1] ?? null) ? $args[1] : []),
            'array_values' => array_values(is_array($args[0] ?? null) ? $args[0] : []),
            'array_keys' => array_keys(is_array($args[0] ?? null) ? $args[0] : []),
            'array_reverse' => array_reverse(is_array($args[0] ?? null) ? $args[0] : [], (bool) ($args[1] ?? false)),
            'array_unique' => array_values(array_unique(is_array($args[0] ?? null) ? $args[0] : [], (int) ($args[1] ?? SORT_STRING))),

            // Math
            'abs' => abs((int) ($args[0] ?? 0)),
            'ceil' => ceil((float) ($args[0] ?? 0)),
            'floor' => floor((float) ($args[0] ?? 0)),
            'max' => max(...$args),
            'min' => min(...$args),
            'round' => round((float) ($args[0] ?? 0), (int) ($args[1] ?? 0)),
            'sqrt' => sqrt((float) ($args[0] ?? 0)),
            'pow' => pow($args[0] ?? 0, $args[1] ?? 0),

            default => WebNativeFunctionRegistry::callLowercase($name, $args),
        };
    }

    /**
     * @param list<mixed> $args
     */
    private static function callPhpCryptoFallback(string $name, array $args): mixed
    {
        if (!function_exists($name)) {
            throw new RuntimeException("PHP crypto fallback function is unavailable: {$name}");
        }

        return $name(...$args);
    }

    /**
     * @param list<mixed> $args
     */
    private static function callImplode(array $args): string
    {
        if (isset($args[0]) && is_array($args[0])) {
            return implode('', $args[0]);
        }

        return implode((string) ($args[0] ?? ''), is_array($args[1] ?? null) ? $args[1] : []);
    }

    /**
     * @param list<mixed> $args
     */
    private static function callStrReplace(array $args): string|array
    {
        if (array_key_exists(3, $args)) {
            $count = (int) $args[3];

            return str_replace($args[0] ?? '', $args[1] ?? '', $args[2] ?? '', $count);
        }

        return str_replace($args[0] ?? '', $args[1] ?? '', $args[2] ?? '');
    }
}