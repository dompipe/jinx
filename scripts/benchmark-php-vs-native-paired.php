<?php

declare(strict_types=1);

require_once __DIR__ . '/native-oracle-sample-args.php';

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$phpWorker = __DIR__ . '/benchmark-php-call.php';

$iterations = 100000;
$limit = 25;
$mode = 'parallel';
$routeFilter = null;
$nameFilter = null;
$onlyNames = null;
$timeout = 15;

foreach (array_slice($argv, 1) as $arg) {
    if (ctype_digit($arg)) {
        $iterations = max(1, (int) $arg);
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = max(0, (int) substr($arg, 8));
    } elseif (str_starts_with($arg, '--mode=')) {
        $mode = strtolower(substr($arg, 7));
    } elseif (str_starts_with($arg, '--route=')) {
        $routeFilter = strtolower(substr($arg, 8));
    } elseif (str_starts_with($arg, '--name=')) {
        $nameFilter = strtolower(substr($arg, 7));
    } elseif (str_starts_with($arg, '--only=')) {
        $rawOnly = substr($arg, 7);
        $onlyNames = array_fill_keys(
            array_values(array_filter(array_map(
                static fn(string $v): string => strtolower(trim($v)),
                explode(',', $rawOnly)
            ))),
            true
        );
    } elseif (str_starts_with($arg, '--timeout=')) {
        $timeout = max(1, (int) substr($arg, 10));
    } else {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(1);
    }
}

if (!in_array($mode, ['parallel', 'serial'], true)) {
    fwrite(STDERR, "--mode must be parallel or serial\n");
    exit(1);
}

function failBench(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function runOne(string $command, int $timeout): array
{
    $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $spec, $pipes);
    if (!is_resource($process)) return [127, 'could not start'];

    foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
    $out = '';
    $start = microtime(true);
    $exit = null;

    while (true) {
        foreach ($pipes as $pipe) {
            $chunk = stream_get_contents($pipe);
            if ($chunk !== false && $chunk !== '') $out .= $chunk;
        }

        $status = proc_get_status($process);
        if (!$status['running']) {
            $exit = $status['exitcode'];
            break;
        }
        if ((microtime(true) - $start) >= $timeout) {
            proc_terminate($process, 9);
            $exit = 124;
            $out .= "\nTIMEOUT";
            break;
        }
        usleep(1000);
    }

    foreach ($pipes as $pipe) {
        $chunk = stream_get_contents($pipe);
        if ($chunk !== false) $out .= $chunk;
        fclose($pipe);
    }
    $close = proc_close($process);
    if (($exit === null || $exit < 0) && $close >= 0) $exit = $close;

    return [$exit ?? 1, trim($out)];
}

function runPair(string $phpCommand, string $jinxCommand, int $timeout): array
{
    $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $commands = ['php' => $phpCommand, 'jinx' => $jinxCommand];
    $processes = [];
    $pipesByName = [];
    $outputs = ['php' => '', 'jinx' => ''];
    $codes = ['php' => null, 'jinx' => null];

    foreach ($commands as $name => $command) {
        $process = proc_open($command, $spec, $pipes);
        if (!is_resource($process)) {
            foreach ($processes as $running) proc_terminate($running, 9);
            return [['php' => 127, 'jinx' => 127], ['php' => 'could not start pair', 'jinx' => 'could not start pair']];
        }
        foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
        $processes[$name] = $process;
        $pipesByName[$name] = $pipes;
    }

    $start = microtime(true);
    while ($processes !== []) {
        foreach ($processes as $name => $process) {
            foreach ($pipesByName[$name] as $pipe) {
                $chunk = stream_get_contents($pipe);
                if ($chunk !== false && $chunk !== '') $outputs[$name] .= $chunk;
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                $codes[$name] = $status['exitcode'];
                foreach ($pipesByName[$name] as $pipe) {
                    $chunk = stream_get_contents($pipe);
                    if ($chunk !== false) $outputs[$name] .= $chunk;
                    fclose($pipe);
                }
                $close = proc_close($process);
                if (($codes[$name] === null || $codes[$name] < 0) && $close >= 0) {
                    $codes[$name] = $close;
                }
                unset($processes[$name], $pipesByName[$name]);
            }
        }

        if ((microtime(true) - $start) >= $timeout && $processes !== []) {
            foreach ($processes as $name => $process) {
                proc_terminate($process, 9);
                $codes[$name] = 124;
                $outputs[$name] .= "\nTIMEOUT";
                foreach ($pipesByName[$name] as $pipe) fclose($pipe);
                proc_close($process);
            }
            $processes = [];
            break;
        }
        usleep(1000);
    }

    return [$codes, array_map('trim', $outputs)];
}

function unsafeFunction(string $name): bool
{
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
        '/^(dns_|checkdnsrr|getmxrr|gethost|fsockopen|pfsockopen|mail$)/',
        '/^(srand|mt_srand|random_bytes)$/',
        '/^(define|class_alias|assert_options)$/',
        '/^(strtok)$/',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $name) === 1) return true;
    }
    return false;
}

function phpFixturesSupported(array $args): bool
{
    foreach ($args as $arg) {
        if ($arg === 'null') continue;
        if (preg_match('/^(i|f|b|s|h):/', $arg) === 1) continue;
        if (preg_match('/^(za:sample|za:empty|za:deleted|za:strings|za:walk)$/', $arg) === 1) continue;
        if (preg_match('/^(dt:|dti:|di:|tz:)/', $arg) === 1) continue;
        return false;
    }
    return true;
}

function parsePhp(string $out): ?float
{
    $data = json_decode($out, true);
    if (!is_array($data) || !isset($data['ns_per_call'])) return null;
    return (float) $data['ns_per_call'];
}

function parseJinx(string $out): ?float
{
    if (preg_match('/Per call ns:\\s*([0-9.]+)/', $out, $m) !== 1) return null;
    return (float) $m[1];
}

if (!is_file($jinx) || !is_executable($jinx)) {
    failBench('compiled repository-root ./jinx is missing; run ./scripts/build-native-jinx.sh');
}
if (is_link($jinx)) failBench('./jinx must not be a symlink');

[$idCode, $idOut] = runOne(escapeshellarg($jinx) . ' native-benchmark-id', 5);
if ($idCode !== 0 || trim($idOut) !== 'native-root-jinx') {
    failBench('compiled ./jinx identity check failed');
}

$wiring = json_decode((string) file_get_contents($root . '/spec/native-oracle-wiring.json'), true);
if (!is_array($wiring['routes'] ?? null)) failBench('native wiring ledger missing');

echo "Paired PHP vs compiled ./jinx benchmark\n";
echo "Mode: {$mode}\n";
echo "Iterations per side per function: {$iterations}\n";
echo "Native executable: ./jinx (identity verified)\n";
echo "Each row launches: php worker <-> ./jinx bench-call\n\n";

$rows = [];
$skipped = 0;

foreach ($wiring['routes'] as $name => $route) {
    $name = (string) $name;
    $route = (string) $route;

    if ($route === 'intentional-native-fault' || str_contains($name, '::')) continue;
    if ($routeFilter !== null && !str_contains(strtolower($route), $routeFilter)) continue;
    if ($nameFilter !== null && !str_contains(strtolower($name), $nameFilter)) continue;
    if ($onlyNames !== null && !isset($onlyNames[strtolower($name)])) continue;
    if (unsafeFunction($name) || !function_exists($name)) {
        ++$skipped;
        continue;
    }

    $typedArgs = jinxNativeOracleSampleArgs($name);
    if (!phpFixturesSupported($typedArgs)) {
        ++$skipped;
        continue;
    }

    $phpCommand = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($phpWorker)
        . ' ' . escapeshellarg($name)
        . ' ' . escapeshellarg((string) $iterations);
    $jinxCommand = escapeshellarg($jinx)
        . ' bench-call ' . escapeshellarg($name)
        . ' ' . escapeshellarg((string) $iterations);
    foreach ($typedArgs as $arg) {
        $quoted = ' ' . escapeshellarg($arg);
        $phpCommand .= $quoted;
        $jinxCommand .= $quoted;
    }

    if ($mode === 'parallel') {
        [$codes, $outputs] = runPair($phpCommand, $jinxCommand, $timeout);
        $phpCode = (int) $codes['php'];
        $jinxCode = (int) $codes['jinx'];
        $phpOut = $outputs['php'];
        $jinxOut = $outputs['jinx'];
    } else {
        [$phpCode, $phpOut] = runOne($phpCommand, $timeout);
        [$jinxCode, $jinxOut] = runOne($jinxCommand, $timeout);
    }

    $phpNs = $phpCode === 0 ? parsePhp($phpOut) : null;
    $jinxNs = $jinxCode === 0 ? parseJinx($jinxOut) : null;
    if ($phpNs === null || $jinxNs === null) {
        ++$skipped;
        continue;
    }

    $ratio = $jinxNs / max($phpNs, 0.000001);
    $rows[] = [
        'name' => $name,
        'route' => $route,
        'php' => $phpNs,
        'jinx' => $jinxNs,
        'ratio' => $ratio,
    ];

    printf(
        "%-34s %-16s PHP %10.1f ns | JINX %10.1f ns | %7.2fx\n",
        $name,
        $route,
        $phpNs,
        $jinxNs,
        $ratio
    );

    if ($limit > 0 && count($rows) >= $limit) break;
}

if ($rows === []) failBench('no paired benchmark rows completed');

$phpTotal = array_sum(array_column($rows, 'php'));
$jinxTotal = array_sum(array_column($rows, 'jinx'));
$ratio = $jinxTotal / max($phpTotal, 0.000001);

echo "\nCross-reference summary\n";
printf("%-12s %12s %12s %12s\n", 'rows', 'PHP mean ns', 'JINX mean ns', 'JINX/PHP');
printf(
    "%-12d %12.1f %12.1f %11.2fx\n",
    count($rows),
    $phpTotal / count($rows),
    $jinxTotal / count($rows),
    $ratio
);
echo "Skipped/rejected before or during paired timing: {$skipped}\n";
echo "Note: parallel mode intentionally measures both engines under simultaneous CPU contention.\n";
