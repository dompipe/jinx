<?php

declare(strict_types=1);

/**
 * Generate the worker-facing native function registry from the imported PHP
 * function manifest. The generated file is metadata only plus a guarded generic
 * call path; hand-written special cases still live in WebNativeFunctions.
 */

$root = dirname(__DIR__);
$manifestPath = $argv[1] ?? ($root . '/spec/php-functions.from-runtime.json');
$outPath = $argv[2] ?? ($root . '/runtime/WebNativeFunctionRegistry.generated.php');

$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$functions = $manifest['functions'] ?? [];

if (!is_array($functions) || count($functions) === 0) {
    fwrite(STDERR, "No functions found in {$manifestPath}\n");
    exit(1);
}

usort(
    $functions,
    static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name'])
);

function jinxWorkerDeniedReason(string $name, string $kind, string $extension, bool $byRef): ?string
{
    static $deniedExtensions = [
        'com_dotnet' => true,
        'dba' => true,
        'ffi' => true,
        'ftp' => true,
        'imap' => true,
        'ldap' => true,
        'mysqli' => true,
        'odbc' => true,
        'pcntl' => true,
        'pdo' => true,
        'pgsql' => true,
        'posix' => true,
        'readline' => true,
        'session' => true,
        'shmop' => true,
        'snmp' => true,
        'sockets' => true,
        'sysvmsg' => true,
        'sysvsem' => true,
        'sysvshm' => true,
    ];

    if ($kind !== 'builtin') {
        return 'not_plain_function';
    }

    if (!function_exists($name)) {
        return 'unavailable_runtime';
    }

    if ($byRef) {
        return 'by_reference_arguments';
    }

    if (isset($deniedExtensions[strtolower($extension)])) {
        return 'denied_extension';
    }

    if ((bool) preg_match(
        '/^(apache_|chdir|chgrp|chmod|chown|clearstatcache|cli_|closelog|copy|curl_|define|dl|disk_|error_log|escapeshell|exec|fastcgi_|fclose|fdatasync|fflush|fgetc|fgetcsv|fgets|file|file_|flock|fopen|fpassthru|fprintf|fputcsv|fputs|fread|fscanf|fseek|fsockopen|fstat|fsync|ftell|ftruncate|fwrite|get_current_user|getcwd|getenv|getmy|getrusage|glob|header|headers_|http_response_code|ignore_user_abort|include|ini_|is_dir|is_executable|is_file|is_link|is_readable|is_uploaded_file|is_writable|link|lstat|mail|mkdir|move_uploaded_file|ob_|opcache_|opendir|openlog|parse_ini_file|passthru|pclose|pfsockopen|php_ini_|php_sapi_name|php_uname|phpcredits|phpinfo|popen|posix_|proc_|putenv|readfile|readlink|realpath|rename|rewind|rmdir|set_error_handler|set_exception_handler|set_file_buffer|set_include_path|set_time_limit|shell_exec|sleep|socket_|stream_|symlink|sys_get|syslog|tempnam|tmpfile|touch|umask|unlink|usleep|virtual|xmlrpc_)/',
        $name
    )) {
        return 'denied_name';
    }

    return null;
}

$wrappers = [];
$nameToId = [];
$rows = [];
foreach ($functions as $function) {
    $name = (string) $function['name'];
    $parameters = is_array($function['parameters'] ?? null) ? $function['parameters'] : [];
    $kind = (string) ($function['kind'] ?? 'builtin');
    $extension = (string) ($function['extension'] ?? '');
    $key = strtolower($name);
    $byRef = (bool) array_filter(
        $parameters,
        static fn (array $param): bool => (bool) ($param['by_ref'] ?? false)
    );
    $deniedReason = jinxWorkerDeniedReason($name, $kind, $extension, $byRef);
    $required = (int) ($function['arity']['required'] ?? count(array_filter(
        $parameters,
        static fn (array $param): bool => (bool) ($param['required'] ?? false)
    )));
    $total = (int) ($function['arity']['total'] ?? count($parameters));
    $variadic = (bool) ($function['arity']['variadic'] ?? false);
    $workerCallable = $deniedReason === null;
    $flags = ($workerCallable ? 1 : 0) | ($variadic ? 2 : 0);
    $id = count($rows);

    $nameToId[$key] = $id;
    $rows[] = [$name, $required, $total, $flags, $deniedReason];

    $wrappers[$key] = [
        'name' => $name,
        'kind' => $kind,
        'owner' => $function['owner'] ?? null,
        'short_name' => (string) ($function['short_name'] ?? $name),
        'extension' => $extension,
        'required' => $required,
        'total' => $total,
        'variadic' => $variadic,
        'by_ref' => $byRef,
        'return' => (string) ($function['return']['type'] ?? 'mixed'),
        'native_strategy' => (string) ($function['native_strategy'] ?? ''),
        'status' => (string) ($function['status'] ?? ''),
        'worker_wrapper' => 'generated-manifest-wrapper',
        'worker_callable' => $workerCallable,
        'blocked_reason' => $deniedReason,
    ];
}

$export = var_export($wrappers, true);
$idExport = var_export($nameToId, true);
$rowExport = var_export($rows, true);
$count = count($wrappers);
$sourceName = (string) ($manifest['manifest']['name'] ?? basename($manifestPath));
$generatedAt = gmdate('c');

$code = <<<PHP
<?php

declare(strict_types=1);

namespace jinx\\web;

/**
 * Generated worker wrapper registry for PHP native functions.
 *
 * Source: {$sourceName}
 * Count: {$count}
 * Generated: {$generatedAt}
 *
 * Do not edit by hand. Run scripts/generate-web-native-function-registry.php.
 */
final class WebNativeFunctionRegistry
{
    public const COUNT = {$count};
    private const FLAG_WORKER_CALLABLE = 1;
    private const FLAG_VARIADIC = 2;

    /** @return array<string,array<string,mixed>> */
    public static function wrappers(): array
    {
        static \$wrappers = null;

        if (\$wrappers === null) {
            \$wrappers = {$export};
        }

        return \$wrappers;
    }

    /** @return array<string,int> */
    private static function nameToId(): array
    {
        static \$nameToId = null;

        if (\$nameToId === null) {
            \$nameToId = {$idExport};
        }

        return \$nameToId;
    }

    /** @return list<array{0:string,1:int,2:int,3:int,4:?string}> */
    private static function rows(): array
    {
        static \$rows = null;

        if (\$rows === null) {
            \$rows = {$rowExport};
        }

        return \$rows;
    }

    /** @return list<string> */
    public static function names(): array
    {
        static \$names = null;

        if (\$names === null) {
            \$names = array_values(array_map(
                static fn (array \$row): string => \$row[0],
                self::rows()
            ));
        }

        return \$names;
    }

    /** @return array<string,mixed>|null */
    public static function wrapper(string \$name): ?array
    {
        \$key = strtolower(\$name);
        \$ids = self::nameToId();

        if (!isset(\$ids[\$key])) {
            return null;
        }

        \$wrappers = self::wrappers();

        return \$wrappers[\$key] ?? null;
    }

    public static function has(string \$name): bool
    {
        \$ids = self::nameToId();

        return isset(\$ids[strtolower(\$name)]);
    }

    /**
     * @param list<mixed> \$args
     */
    public static function call(string \$name, array \$args): mixed
    {
        return self::callLowercase(strtolower(\$name), \$args);
    }

    /**
     * @param list<mixed> \$args
     */
    public static function callLowercase(string \$name, array \$args): mixed
    {
        \$ids = self::nameToId();

        if (!isset(\$ids[\$name])) {
            throw new \\RuntimeException("Native function has no JINX worker wrapper: {\$name}");
        }

        \$row = self::rows()[\$ids[\$name]];
        \$flags = \$row[3];

        if ((\$flags & self::FLAG_WORKER_CALLABLE) === 0) {
            throw new \\RuntimeException("JINX worker wrapper for {\$row[0]} is metadata-only or blocked: " . (string) \$row[4]);
        }

        \$required = \$row[1];
        \$total = \$row[2];
        \$argc = count(\$args);

        if (\$argc < \$required) {
            throw new \\RuntimeException("JINX worker wrapper for {\$row[0]} expected at least {\$required} argument(s), got {\$argc}");
        }

        if ((\$flags & self::FLAG_VARIADIC) === 0 && \$argc > \$total) {
            throw new \\RuntimeException("JINX worker wrapper for {\$row[0]} expected at most {\$total} argument(s), got {\$argc}");
        }

        return \$name(...\$args);
    }
}

PHP;

$dir = dirname($outPath);
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

file_put_contents($outPath, $code);
echo "PASS: generated {$count} worker-style native wrappers at {$outPath}\n";
