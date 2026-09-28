<?php

declare(strict_types=1);

require_once __DIR__ . '/native-oracle-sample-args.php';

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$iterations = 1000;
$limit = 0;
$routeFilter = null;
$nameFilter = null;
$caseTimeout = 10;
$showProgress = true;
$buildFirst = getenv('JINX_SKIP_BUILD') !== '1';

foreach (array_slice($argv, 1) as $arg) {
    if (ctype_digit($arg)) {
        $iterations = max(1, (int) $arg);
        continue;
    }
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(0, (int) substr($arg, strlen('--limit=')));
        continue;
    }
    if (str_starts_with($arg, '--route=')) {
        $routeFilter = strtolower(substr($arg, strlen('--route=')));
        continue;
    }
    if (str_starts_with($arg, '--name=')) {
        $nameFilter = strtolower(substr($arg, strlen('--name=')));
        continue;
    }
    if (str_starts_with($arg, '--case-timeout=')) {
        $caseTimeout = max(1, (int) substr($arg, strlen('--case-timeout=')));
        continue;
    }
    if ($arg === '--quiet') {
        $showProgress = false;
        continue;
    }
    if ($arg === '--no-build') {
        $buildFirst = false;
        continue;
    }

    fwrite(STDERR, "Unknown option: {$arg}\n");
    exit(1);
}

function benchFail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function benchRun(
    string $command,
    ?int &$code = null,
    int $timeoutSeconds = 30
): string {
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open('exec ' . $command, $descriptors, $pipes);
    if (!is_resource($process)) {
        $code = 127;
        return 'could not start command';
    }

    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }

    $output = '';
    $started = microtime(true);
    $exitCode = null;

    while (true) {
        $read = [];
        foreach ($pipes as $pipe) {
            if (!feof($pipe)) $read[] = $pipe;
        }

        if ($read !== []) {
            $write = null;
            $except = null;
            @stream_select($read, $write, $except, 0, 200000);
            foreach ($read as $pipe) {
                $chunk = stream_get_contents($pipe);
                if ($chunk !== false) $output .= $chunk;
            }
        } else {
            usleep(10000);
        }

        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = $status['exitcode'];
            break;
        }

        if ((microtime(true) - $started) >= $timeoutSeconds) {
            proc_terminate($process, 9);
            $exitCode = 124;
            $output .= PHP_EOL . "TIMEOUT after {$timeoutSeconds}s";
            break;
        }
    }

    foreach ($pipes as $pipe) {
        $chunk = stream_get_contents($pipe);
        if ($chunk !== false) $output .= $chunk;
        fclose($pipe);
    }

    $closed = proc_close($process);
    if (($exitCode === null || $exitCode < 0) && $closed >= 0) {
        $exitCode = $closed;
    }

    $code = $exitCode ?? 1;
    return rtrim($output, "\r\n");
}

function benchRequireOk(string $command, int $timeoutSeconds = 180): string
{
    $output = benchRun($command, $code, $timeoutSeconds);
    if ($code !== 0) {
        benchFail("command failed ({$code}): {$command}\n{$output}");
    }
    return $output;
}

function benchQuiet(callable $fn): mixed
{
    set_error_handler(static function (int $severity, string $message): never {
        throw new RuntimeException($message, $severity);
    });

    try {
        return $fn();
    } finally {
        restore_error_handler();
    }
}

/**
 * Translate the native CLI fixture language into the equivalent PHP value.
 *
 * @return array{0:bool,1:mixed,2:string}
 */
function benchDecodeArg(string $typed): array
{
    if ($typed === 'null') return [true, null, ''];
    if (str_starts_with($typed, 'i:')) return [true, (int) substr($typed, 2), ''];
    if (str_starts_with($typed, 'f:')) return [true, (float) substr($typed, 2), ''];
    if (str_starts_with($typed, 'b:')) {
        $raw = strtolower(substr($typed, 2));
        return [true, $raw === 'true' || $raw === '1', ''];
    }
    if (str_starts_with($typed, 's:')) return [true, substr($typed, 2), ''];
    if (str_starts_with($typed, 'h:')) {
        $value = hex2bin(substr($typed, 2));
        return $value === false
            ? [false, null, 'invalid hex fixture']
            : [true, $value, ''];
    }

    return match ($typed) {
        'za:sample' => [true, [10, 20, 'name' => 30, 'keep' => 40], ''],
        'za:empty' => [true, [], ''],
        'za:deleted' => [true, [0 => 10, 'keep' => 40], ''],
        'za:strings' => [true, ['b', 'a', 'c'], ''],
        'za:walk' => [true, [1, 2, 3], ''],
        default => match (true) {
            str_starts_with($typed, 'dt:') => [true, new DateTime(substr($typed, 3)), ''],
            str_starts_with($typed, 'dti:') => [true, new DateTimeImmutable(substr($typed, 4)), ''],
            str_starts_with($typed, 'di:') => [
                ($v = DateInterval::createFromDateString(substr($typed, 3))) !== false,
                $v === false ? null : $v,
                $v === false ? 'invalid DateInterval fixture' : '',
            ],
            str_starts_with($typed, 'tz:') => [true, new DateTimeZone(substr($typed, 3)), ''],
            default => [false, null, 'native-only carrier ' . strtok($typed, ':')],
        },
    };
}

/**
 * @param list<string> $typedArgs
 * @return array{0:bool,1:list<mixed>,2:string}
 */
function benchDecodeArgs(array $typedArgs): array
{
    $args = [];
    foreach ($typedArgs as $typed) {
        try {
            [$ok, $value, $reason] = benchDecodeArg($typed);
        } catch (Throwable $e) {
            return [false, [], $e->getMessage()];
        }

        if (!$ok) {
            return [false, [], $reason];
        }
        $args[] = $value;
    }

    return [true, $args, ''];
}

function benchUnsafePhpFunction(string $name): ?string
{
    /*
     * Benchmarking should never mutate the checkout, process identity, runtime
     * configuration, network state, or sleep. These functions can still have
     * parity proofs; they are simply excluded from a timing loop.
     */
    $patterns = [
        '/^(sleep|usleep|time_nanosleep|time_sleep_until)$/',
        '/^(exec|system|passthru|shell_exec|popen|pclose|proc_)/',
        '/^(putenv|setlocale|ini_set|ini_restore|date_default_timezone_set)$/',
        '/^(chdir|chroot|chmod|chown|chgrp|lchown|lchgrp|mkdir|rmdir|unlink|rename|copy|touch|symlink|link)$/',
        '/^(file|fopen|fclose|fread|fwrite|fseek|ftell|fgetc|fgets|fgetcsv|fputcsv|file_put_contents|readfile|glob|scandir|stat|lstat|realpath|opendir|readdir|rewinddir|closedir|tmpfile|tempnam|fputs|fprintf|vfprintf|fflush|ftruncate|flock|fsync|fdatasync)/',
        '/^(gz|zlib_|deflate_|inflate_)/',
        '/^(date_add|date_sub|date_modify|date_date_set|date_time_set|date_isodate_set|date_timestamp_set|date_timezone_set)$/',
        '/^(openlog|closelog|syslog|error_log)$/',
        '/^(header|setcookie|setrawcookie|http_response_code)$/',
        '/^(posix_set|posix_kill|pcntl_|cli_set_process_title)$/',
        '/^(session_|socket_|stream_socket_|curl_|ftp_|mysqli_|pg_|sqlite_|odbc_|ldap_)/',
        '/^(srand|mt_srand)$/',
        '/^(define|class_alias|assert_options)$/',
        '/^(strtok)$/',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $name) === 1) {
            return 'stateful or side-effecting PHP call';
        }
    }

    return null;
}

/**
 * @param list<mixed> $args
 */
function benchPhpCase(string $name, array $args, int $iterations): array
{
    if (!function_exists($name)) {
        return [false, 0.0, 'not available as a PHP global function'];
    }

    if (($reason = benchUnsafePhpFunction($name)) !== null) {
        return [false, 0.0, $reason];
    }

    try {
        $reflection = new ReflectionFunction($name);
        foreach ($reflection->getParameters() as $index => $parameter) {
            if ($index >= count($args)) break;
            if ($parameter->isPassedByReference()) {
                return [false, 0.0, 'requires by-reference PHP argument'];
            }
        }

        benchQuiet(static fn (): mixed => $name(...$args));
    } catch (Throwable $e) {
        return [false, 0.0, 'PHP sample rejected: ' . $e->getMessage()];
    }

    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $name(...$args);
    }
    $elapsed = hrtime(true) - $start;

    return [true, $elapsed / $iterations, ''];
}

/**
 * @param list<string> $typedArgs
 */
function benchNativeFunction(
    string $jinx,
    string $name,
    array $typedArgs,
    int $iterations,
    int $timeoutSeconds
): array {
    /*
     * bench-call already performs one checked probe before timing. Running a
     * separate oracle-call here doubled process launches and let one slow
     * native route stall the whole benchmark before PHP safety filtering.
     */
    $command = escapeshellarg($jinx)
        . ' bench-call '
        . escapeshellarg($name)
        . ' '
        . escapeshellarg((string) $iterations);
    foreach ($typedArgs as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }

    $output = benchRun($command, $code, $timeoutSeconds);
    if ($code === 124) {
        return [false, 0.0, "native case timed out after {$timeoutSeconds}s"];
    }
    if ($code !== 0) {
        return [false, 0.0, 'native benchmark failed: ' . preg_replace('/\s+/', ' ', trim($output))];
    }
    if (preg_match('/Per call ns:\s*([0-9.]+)/', $output, $m) !== 1) {
        return [false, 0.0, 'native benchmark did not report per-call timing'];
    }

    return [true, (float) $m[1], ''];
}

function benchProofForRoute(string $route): string
{
    return match ($route) {
        'asm' => 'native ASM parity suites',
        'asm+zend-container', 'zend-container' => 'test-native-zend-array-core-oracle-asm.php',
        'extended' => 'procedural parity suites',
        'context' => 'context smoke/parity suites',
        default => 'native parity suite',
    };
}

if ($buildFirst) {
    echo "Building native ./jinx before benchmark...\n";
    fflush(STDOUT);
    benchRequireOk(
        'cd ' . escapeshellarg($root) . ' && ./scripts/build-native-jinx.sh',
        300
    );
    echo "Build complete. Starting implementation benchmark.\n";
    fflush(STDOUT);
}
if (!is_file($jinx) || !is_executable($jinx)) {
    benchFail('repository-root native ./jinx missing or not executable; run ./scripts/build-native-jinx.sh first');
}

$wiringPath = $root . '/spec/native-oracle-wiring.json';
$wiring = json_decode((string) file_get_contents($wiringPath), true);
if (!is_array($wiring) || !isset($wiring['routes']) || !is_array($wiring['routes'])) {
    benchFail('could not read spec/native-oracle-wiring.json');
}

$rows = [];
$skipped = [];
$routeCounts = [];
$attempted = 0;

echo "Scanning implemented routes (per-case timeout: {$caseTimeout}s)...\n";
fflush(STDOUT);

foreach ($wiring['routes'] as $name => $route) {
    $name = (string) $name;
    $route = (string) $route;

    if ($route === 'intentional-native-fault' || str_contains($name, '::')) {
        continue;
    }
    if ($routeFilter !== null && !str_contains(strtolower($route), $routeFilter)) {
        continue;
    }
    if ($nameFilter !== null && !str_contains(strtolower($name), $nameFilter)) {
        continue;
    }

    ++$attempted;
    if ($showProgress) {
        printf("[%d] %-42s ", $attempted, $name);
        fflush(STDOUT);
    }

    if (($unsafeReason = benchUnsafePhpFunction($name)) !== null) {
        $skipped[] = [$name, $route, $unsafeReason];
        if ($showProgress) echo "SKIP ({$unsafeReason})\n";
        continue;
    }
    if (!function_exists($name)) {
        $reason = 'not available as a PHP global function';
        $skipped[] = [$name, $route, $reason];
        if ($showProgress) echo "SKIP ({$reason})\n";
        continue;
    }

    $typedArgs = jinxNativeOracleSampleArgs($name);
    [$decodable, $phpArgs, $decodeReason] = benchDecodeArgs($typedArgs);
    if (!$decodable) {
        $skipped[] = [$name, $route, $decodeReason];
        if ($showProgress) echo "SKIP ({$decodeReason})\n";
        continue;
    }

    /*
     * Validate and time PHP first. If the fixture is invalid for PHP, do not
     * launch the native timing loop at all.
     */
    [$phpOk, $phpNs, $phpReason] = benchPhpCase($name, $phpArgs, $iterations);
    if (!$phpOk) {
        $skipped[] = [$name, $route, $phpReason];
        if ($showProgress) echo "SKIP ({$phpReason})\n";
        continue;
    }

    [$nativeOk, $nativeNs, $nativeReason] = benchNativeFunction(
        $jinx, $name, $typedArgs, $iterations, $caseTimeout
    );
    if (!$nativeOk) {
        $skipped[] = [$name, $route, $nativeReason];
        if ($showProgress) echo "SKIP ({$nativeReason})\n";
        continue;
    }

    if ($showProgress) {
        printf("OK (PHP %.1f ns, JINX %.1f ns)\n", $phpNs, $nativeNs);
    }

    $rows[] = [
        'name' => $name,
        'route' => $route,
        'ledger' => $route,
        'proof' => benchProofForRoute($route),
        'php_ns' => $phpNs,
        'jinx_ns' => $nativeNs,
        'ratio' => $nativeNs / max($phpNs, 0.000001),
    ];
    $routeCounts[$route] = ($routeCounts[$route] ?? 0) + 1;

    if ($limit > 0 && count($rows) >= $limit) {
        break;
    }
}

/*
 * Native method routes need receiver fixtures, so benchmark the currently
 * parity-proven method set explicitly while still associating each row with
 * the reviewed wiring ledger route.
 */
$methodCases = [
    [
        'name' => 'DateTime::format',
        'receiver' => 'dt:2024-01-02 03:04:05',
        'args' => ['s:Y-m-d'],
        'object' => new DateTime('2024-01-02 03:04:05'),
        'method' => 'format',
        'php_args' => ['Y-m-d'],
    ],
    [
        'name' => 'DateTimeImmutable::format',
        'receiver' => 'dti:2024-01-02 03:04:05',
        'args' => ['s:H:i:s'],
        'object' => new DateTimeImmutable('2024-01-02 03:04:05'),
        'method' => 'format',
        'php_args' => ['H:i:s'],
    ],
    [
        'name' => 'DateTime::getTimestamp',
        'receiver' => 'dt:2024-01-02 03:04:05',
        'args' => [],
        'object' => new DateTime('2024-01-02 03:04:05'),
        'method' => 'getTimestamp',
        'php_args' => [],
    ],
    [
        'name' => 'DateTimeImmutable::getTimestamp',
        'receiver' => 'dti:2024-01-02 03:04:05',
        'args' => [],
        'object' => new DateTimeImmutable('2024-01-02 03:04:05'),
        'method' => 'getTimestamp',
        'php_args' => [],
    ],
    [
        'name' => 'DateTime::getOffset',
        'receiver' => 'dt:2024-01-02 03:04:05',
        'args' => [],
        'object' => new DateTime('2024-01-02 03:04:05'),
        'method' => 'getOffset',
        'php_args' => [],
    ],
    [
        'name' => 'DateTimeImmutable::getOffset',
        'receiver' => 'dti:2024-01-02 03:04:05',
        'args' => [],
        'object' => new DateTimeImmutable('2024-01-02 03:04:05'),
        'method' => 'getOffset',
        'php_args' => [],
    ],
    [
        'name' => 'DateTimeZone::getName',
        'receiver' => 'tz:UTC',
        'args' => [],
        'object' => new DateTimeZone('UTC'),
        'method' => 'getName',
        'php_args' => [],
    ],
    [
        'name' => 'DateTimeZone::getOffset',
        'receiver' => 'tz:UTC',
        'args' => ['dt:2024-01-02 03:04:05'],
        'object' => new DateTimeZone('UTC'),
        'method' => 'getOffset',
        'php_args' => [new DateTime('2024-01-02 03:04:05')],
    ],
    [
        'name' => 'DateInterval::format',
        'receiver' => 'di:P1Y2M3DT4H5M6S',
        'args' => ['s:%Y-%M-%D %H:%I:%S'],
        'object' => new DateInterval('P1Y2M3DT4H5M6S'),
        'method' => 'format',
        'php_args' => ['%Y-%M-%D %H:%I:%S'],
    ],
];

/*
 * Throwable getter routes share one deterministic ex:<Class> receiver fixture.
 * Discover them from the reviewed inventory so a newly promoted Throwable
 * class automatically becomes benchmarkable without duplicating the parity
 * suite's class list here.
 */
$throwableMethodNames = [
    'getmessage' => 'getMessage',
    'getcode' => 'getCode',
    'getprevious' => 'getPrevious',
    'gettrace' => 'getTrace',
    'gettraceasstring' => 'getTraceAsString',
    'getfile' => 'getFile',
    'getline' => 'getLine',
    '__tostring' => '__toString',
];
$declaredClassNames = [];
foreach (get_declared_classes() as $declaredClass) {
    $declaredClassNames[strtolower($declaredClass)] = $declaredClass;
}

foreach ($wiring['routes'] as $ledgerName => $ledgerRoute) {
    if (!str_contains((string) $ledgerName, '::')) continue;
    [$classLower, $methodLower] = explode('::', strtolower((string) $ledgerName), 2);
    if (!isset($throwableMethodNames[$methodLower], $declaredClassNames[$classLower])) continue;

    $class = $declaredClassNames[$classLower];
    if (!is_a($class, Throwable::class, true)) continue;

    try {
        $object = new $class('jinx-message', 73);
        (new ReflectionProperty($class, 'file'))->setValue($object, 'jinx-fixture.php');
        (new ReflectionProperty($class, 'line'))->setValue($object, 123);
    } catch (Throwable) {
        continue;
    }

    $methodCases[] = [
        'name' => $class . '::' . $throwableMethodNames[$methodLower],
        'receiver' => 'ex:' . $class,
        'args' => [],
        'object' => $object,
        'method' => $throwableMethodNames[$methodLower],
        'php_args' => [],
    ];
}

if ($limit === 0 || count($rows) < $limit) {
    foreach ($methodCases as $case) {
        $ledgerRoute = (string) ($wiring['routes'][strtolower($case['name'])] ?? 'unlisted');
        $caseRoute = 'method-dispatch';

        if ($routeFilter !== null &&
            !str_contains(strtolower($caseRoute), $routeFilter) &&
            !str_contains(strtolower($ledgerRoute), $routeFilter)) {
            continue;
        }
        if ($nameFilter !== null && !str_contains(strtolower($case['name']), $nameFilter)) {
            continue;
        }

        $probe = escapeshellarg($jinx)
            . ' oracle-method-call '
            . escapeshellarg($case['name'])
            . ' '
            . escapeshellarg($case['receiver']);
        foreach ($case['args'] as $arg) {
            $probe .= ' ' . escapeshellarg($arg);
        }
        ++$attempted;
        if ($showProgress) {
            printf("[%d] %-42s ", $attempted, $case['name']);
            fflush(STDOUT);
        }

        $probeOutput = benchRun($probe, $probeCode, $caseTimeout);
        if ($probeCode !== 0 || str_starts_with($probeOutput, 'null/fault')) {
            $reason = $probeCode === 124
                ? "native method probe timed out after {$caseTimeout}s"
                : 'native method sample rejected';
            $skipped[] = [$case['name'], $caseRoute, $reason];
            if ($showProgress) echo "SKIP ({$reason})\n";
            continue;
        }

        $nativeCommand = escapeshellarg($jinx)
            . ' bench-method-call '
            . escapeshellarg($case['name'])
            . ' '
            . escapeshellarg((string) $iterations)
            . ' '
            . escapeshellarg($case['receiver']);
        foreach ($case['args'] as $arg) {
            $nativeCommand .= ' ' . escapeshellarg($arg);
        }

        $nativeOutput = benchRun($nativeCommand, $nativeCode, $caseTimeout);
        if ($nativeCode !== 0 || preg_match('/Per call ns:\\s*([0-9.]+)/', $nativeOutput, $m) !== 1) {
            $reason = $nativeCode === 124
                ? "native method benchmark timed out after {$caseTimeout}s"
                : 'native method benchmark failed';
            $skipped[] = [$case['name'], $caseRoute, $reason];
            if ($showProgress) echo "SKIP ({$reason})\n";
            continue;
        }
        $nativeNs = (float) $m[1];

        $object = $case['object'];
        $method = $case['method'];
        $phpArgs = $case['php_args'];
        benchQuiet(static fn (): mixed => $object->{$method}(...$phpArgs));
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $object->{$method}(...$phpArgs);
        }
        $phpNs = (hrtime(true) - $start) / $iterations;

        if ($showProgress) {
            printf("OK (PHP %.1f ns, JINX %.1f ns)\n", $phpNs, $nativeNs);
        }

        $rows[] = [
            'name' => $case['name'],
            'route' => $caseRoute,
            'ledger' => $ledgerRoute,
            'proof' => 'test-native-method-oracle-asm.php',
            'php_ns' => $phpNs,
            'jinx_ns' => $nativeNs,
            'ratio' => $nativeNs / max($phpNs, 0.000001),
        ];
        $routeCounts[$caseRoute] = ($routeCounts[$caseRoute] ?? 0) + 1;

        if ($limit > 0 && count($rows) >= $limit) {
            break;
        }
    }
}

if ($rows === []) {
    benchFail('no safely benchmarkable implemented functions matched the requested filters');
}

$totalPhpNs = array_sum(array_column($rows, 'php_ns'));
$totalJinxNs = array_sum(array_column($rows, 'jinx_ns'));

printf("PHP vs native ./jinx implemented-function benchmark\n");
printf("Association source: builtin wiring ledger + parity-proven method dispatcher cases\n");
printf("Shared fixture source: scripts/native-oracle-sample-args.php\n");
printf("Iterations per implementation: %d\n", $iterations);
printf("Benchmarked implementations: %d\n", count($rows));
printf("Skipped unsafe/untranslatable/rejected cases: %d\n", count($skipped));
printf("\n");
printf("%-38s %-18s %-24s %12s %12s %9s  %s\n", 'implementation', 'actual route', 'ledger route', 'php ns/op', 'jinx ns/op', 'ratio', 'proof');
printf("%'-145s\n", '');

foreach ($rows as $row) {
    printf(
        "%-38s %-18s %-24s %12.1f %12.1f %8.2fx  %s\n",
        $row['name'],
        $row['route'],
        $row['ledger'],
        $row['php_ns'],
        $row['jinx_ns'],
        $row['ratio'],
        $row['proof']
    );
}

printf("%'-145s\n", '');
printf(
    "%-38s %-18s %-24s %12.1f %12.1f %8.2fx\n",
    'MEAN',
    '',
    '',
    $totalPhpNs / count($rows),
    $totalJinxNs / count($rows),
    $totalJinxNs / max($totalPhpNs, 0.000001)
);

ksort($routeCounts);
echo "\nBenchmarked by implementation route:\n";
foreach ($routeCounts as $route => $count) {
    printf("- %-20s %d\n", $route, $count);
}

if ($skipped !== []) {
    echo "\nFirst skipped cases:\n";
    foreach (array_slice($skipped, 0, 20) as [$name, $route, $reason]) {
        echo "- {$name} [{$route}]: {$reason}\n";
    }
}
