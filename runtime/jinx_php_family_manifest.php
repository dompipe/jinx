<?php

declare(strict_types=1);

namespace jinx\runtime;

/**
 * Oracle/PASM implementation family map for generated PHP callable names.
 *
 * This is the bridge between the flat generated callable surface and the
 * real php-src rewrite work. Each family tells JINX which runtime subsystem
 * owns the behavior, which Oracle/PASM target should receive it, and whether
 * it is exact-native today, delegated to original PHP for correctness, or
 * blocked until a sandbox/runtime model exists.
 */
final class JinxPhpFamilyManifest
{
    /** @return array<string,array<string,mixed>> */
    public static function families(): array
    {
        return [
            'scalar-core' => [
                'state' => 'exact-native',
                'oracle_target' => 'build/oracle-asm/jinx_oracle_asm_runtime.h',
                'pasm_target' => 'future/runtime/pasm/scalar.pasm',
                'php_src' => ['Zend/', 'ext/standard/type.c'],
                'description' => 'Booleans, ints, floats, null checks, simple scalar conversions.',
                'prefixes' => ['is_'],
                'contains' => ['boolval', 'intval', 'floatval', 'strval'],
            ],
            'math' => [
                'state' => 'exact-native',
                'oracle_target' => 'build/oracle-asm/jinx_oracle_asm_runtime.h',
                'pasm_target' => 'future/runtime/pasm/math.pasm',
                'php_src' => ['ext/standard/math.c'],
                'description' => 'Scalar math and libm-backed trig/log functions.',
                'exact' => ['abs', 'acos', 'acosh', 'asin', 'asinh', 'atan', 'atan2', 'atanh', 'ceil', 'floor', 'sqrt', 'sin', 'sinh', 'tan', 'tanh', 'cos', 'cosh', 'exp', 'expm1', 'log', 'log10'],
            ],
            'string-core' => [
                'state' => 'mixed-native-and-fallback',
                'oracle_target' => 'build/oracle-asm/jinx_oracle_asm_runtime.h',
                'pasm_target' => 'future/runtime/pasm/string.pasm',
                'php_src' => ['ext/standard/string.c', 'ext/standard/html.c'],
                'description' => 'String length/search/prefix/suffix exact-native first; transforms remain PHP fallback until individually lowered.',
                'exact' => ['strlen', 'str_contains', 'str_starts_with', 'str_ends_with'],
                'prefixes' => ['str', 'html'],
                'contains' => ['basename', 'dirname', 'bin2hex', 'chr', 'ord', 'trim', 'sprintf', 'printf', 'wordwrap', 'explode', 'implode'],
            ],
            'ctype' => [
                'state' => 'exact-native',
                'oracle_target' => 'build/oracle-asm/jinx_oracle_asm_runtime.h',
                'pasm_target' => 'future/runtime/pasm/ctype.pasm',
                'php_src' => ['ext/ctype/ctype.c'],
                'description' => 'ASCII byte-class checks for native string values.',
                'prefixes' => ['ctype_'],
            ],
            'array' => [
                'state' => 'php-fallback-until-native-hashtable',
                'oracle_target' => 'future/runtime/oracle/array.oracle_asm.h',
                'pasm_target' => 'future/runtime/pasm/array.pasm',
                'php_src' => ['ext/standard/array.c', 'Zend/zend_hash.c'],
                'description' => 'Array functions need native HashTable/zend_array equivalent before exact native lowering.',
                'prefixes' => ['array_'],
                'exact' => ['count'],
                'contains' => ['in_array', 'range', 'compact', 'extract'],
            ],
            'json' => [
                'state' => 'php-fallback',
                'oracle_target' => 'future/runtime/oracle/json.oracle_asm.h',
                'pasm_target' => 'future/runtime/pasm/json.pasm',
                'php_src' => ['ext/json/'],
                'description' => 'JSON encode/decode stay PHP fallback until native arrays/objects/strings are complete.',
                'prefixes' => ['json_'],
                'contains' => ['json'],
            ],
            'regex-pcre' => [
                'state' => 'php-fallback',
                'oracle_target' => 'future/runtime/oracle/pcre.oracle_asm.h',
                'pasm_target' => 'future/runtime/pasm/pcre.pasm',
                'php_src' => ['ext/pcre/'],
                'description' => 'PCRE calls delegate to original PHP/PCRE until a native PCRE binding policy exists.',
                'prefixes' => ['preg_', 'pcre_'],
            ],
            'crypto' => [
                'state' => 'php-fallback',
                'oracle_target' => 'future/runtime/oracle/crypto.oracle_asm.h',
                'pasm_target' => 'future/runtime/pasm/crypto.pasm',
                'php_src' => ['ext/hash/', 'ext/openssl/', 'ext/random/', 'ext/sodium/', 'ext/standard/md5.c', 'ext/standard/sha1.c'],
                'description' => 'Correctness-first fallback to original PHP for hash/password/random/openssl/sodium until native crypto is reviewed.',
                'prefixes' => ['hash', 'password_', 'random_', 'openssl_', 'sodium_'],
                'exact' => ['md5', 'md5_file', 'sha1', 'sha1_file', 'crc32', 'crypt'],
            ],
            'date-time' => [
                'state' => 'php-fallback',
                'oracle_target' => 'future/runtime/oracle/date.oracle_asm.h',
                'pasm_target' => 'future/runtime/pasm/date.pasm',
                'php_src' => ['ext/date/'],
                'description' => 'Date/time functions need timezone database and object/runtime model.',
                'prefixes' => ['date_', 'timezone_', 'time'],
                'contains' => ['DateTime', 'date', 'gmdate', 'mktime'],
            ],
            'class-object-reflection' => [
                'state' => 'php-fallback-until-object-model',
                'oracle_target' => 'future/runtime/oracle/object.oracle_asm.h',
                'pasm_target' => 'future/runtime/pasm/object.pasm',
                'php_src' => ['Zend/zend_object_handlers.c', 'Zend/zend_class.c', 'ext/reflection/'],
                'description' => 'Classes, methods, reflection, exceptions, iterators, and object methods need native class/object tables.',
                'contains' => ['::', 'class', 'Class', 'Reflection', 'Exception', 'Error', 'Iterator', 'ArrayObject'],
                'prefixes' => ['get_class', 'class_', 'interface_', 'trait_', 'method_', 'property_'],
            ],
            'filesystem-stream-process-network-session-db' => [
                'state' => 'sandbox-blocked',
                'oracle_target' => 'future/runtime/oracle/sandbox.oracle_asm.h',
                'pasm_target' => 'future/runtime/pasm/sandbox.pasm',
                'php_src' => ['ext/standard/file.c', 'ext/standard/proc_open.c', 'ext/session/', 'ext/pdo/', 'ext/mysqli/', 'ext/curl/', 'main/streams/'],
                'description' => 'Side-effecting host/resource functions are blocked until explicit sandbox policy exists.',
                'prefixes' => ['file', 'fopen', 'fread', 'fwrite', 'stream_', 'socket_', 'session_', 'pdo_', 'mysqli_', 'curl_', 'proc_', 'exec', 'shell_'],
                'contains' => ['file', 'stream', 'socket', 'session', 'curl', 'pdo', 'mysqli', 'mysql', 'exec', 'proc'],
            ],
            'spl-iterator' => [
                'state' => 'php-fallback-until-object-model',
                'oracle_target' => 'future/runtime/oracle/spl.oracle_asm.h',
                'pasm_target' => 'future/runtime/pasm/spl.pasm',
                'php_src' => ['ext/spl/'],
                'description' => 'SPL objects and iterator methods need native object/interface/iterator model.',
                'contains' => ['Iterator', 'ArrayObject', 'Spl', 'DirectoryIterator', 'Recursive'],
            ],
            'misc-extension' => [
                'state' => 'php-fallback',
                'oracle_target' => 'future/runtime/oracle/misc.oracle_asm.h',
                'pasm_target' => 'future/runtime/pasm/misc.pasm',
                'php_src' => ['ext/*'],
                'description' => 'Known callable but no lower-priority native family yet.',
                'fallback' => true,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function classify(string $name): array
    {
        $lower = strtolower($name);

        foreach (self::families() as $family => $spec) {
            foreach (($spec['exact'] ?? []) as $exact) {
                if ($lower === strtolower((string)$exact)) {
                    return ['family' => $family] + $spec;
                }
            }
        }

        foreach (self::families() as $family => $spec) {
            foreach (($spec['prefixes'] ?? []) as $prefix) {
                if (str_starts_with($lower, strtolower((string)$prefix))) {
                    return ['family' => $family] + $spec;
                }
            }

            foreach (($spec['contains'] ?? []) as $needle) {
                if (str_contains($name, (string)$needle) || str_contains($lower, strtolower((string)$needle))) {
                    return ['family' => $family] + $spec;
                }
            }
        }

        return ['family' => 'misc-extension'] + self::families()['misc-extension'];
    }
}
