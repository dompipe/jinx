<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$php = PHP_BINARY;
$caseDir = $root . '/build/differential/php-vs-jinx-high-value-edge-fixtures';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

/** @return array{exit:int,stdout:string,stderr:string} */
function run_process(array $command): array
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $environment = getenv();
    if ($command[0] === dirname(__DIR__) . '/jinx') {
        $environment['PATH'] = '/jinx-test-no-executables';
        $environment['JINX_NATIVE_ONLY'] = '1';
        $environment['JINX_ORACLE_SCRIPT_RUNNER'] = '/jinx-test-bridge-must-not-run';
    }
    $process = proc_open($command, $descriptorSpec, $pipes, null, $environment);
    if (!is_resource($process)) {
        fail('could not start process: ' . implode(' ', $command));
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exit = proc_close($process);

    return [
        'exit' => is_int($exit) ? $exit : 1,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

/** @param array<string,array{area:string,source:string}> $cases */
function add_case(array &$cases, string $area, string $name, string $body): void
{
    if (isset($cases[$name])) {
        fail('duplicate edge fixture: ' . $name);
    }

    $cases[$name] = [
        'area' => $area,
        'source' => "<?php\n\ndeclare(strict_types=1);\nerror_reporting(E_ALL);\n\n" . $body . "\n",
    ];
}

if (!is_file($jinx) || !is_executable($jinx)) {
    fail('repository-root native ./jinx missing or not executable; run ./scripts/build-native-jinx.sh first');
}

if (!is_dir($caseDir) && !mkdir($caseDir, 0777, true) && !is_dir($caseDir)) {
    fail('could not create high-value edge fixture directory: ' . $caseDir);
}

$cases = [];

add_case($cases, 'references', 'references-alias-mutation', <<<'PHP'
$a = ['n' => 1];
$b =& $a['n'];
$b += 4;
$a['m'] =& $b;
$a['m']++;
echo json_encode(['a' => $a, 'b' => $b]) . "\n";
PHP);

add_case($cases, 'references', 'references-foreach-byref-unset', <<<'PHP'
$items = [1, 2, 3];
foreach ($items as &$value) {
    $value *= 2;
}
unset($value);
$value = 99;
echo json_encode($items) . "\n";
PHP);

add_case($cases, 'zend-arrays', 'zend-array-key-collisions', <<<'PHP'
$values = [];
$values[1] = 'int';
$values['1'] = 'numeric-string';
$values[true] = 'bool';
$values[null] = 'null-key';
$values[''] = 'empty-string';
echo json_encode($values) . "\n";
PHP);

add_case($cases, 'zend-arrays', 'zend-array-nested-copy-on-write', <<<'PHP'
$a = ['x' => ['n' => 1]];
$b = $a;
$b['x']['n'] = 7;
echo json_encode(['a' => $a, 'b' => $b]) . "\n";
PHP);

add_case($cases, 'object-statics', 'static-property-inheritance-shadow', <<<'PHP'
class EdgeStaticBase { public static int $count = 1; }
class EdgeStaticChild extends EdgeStaticBase { public static int $count = 10; }
EdgeStaticBase::$count += 2;
EdgeStaticChild::$count += 3;
echo json_encode([EdgeStaticBase::$count, EdgeStaticChild::$count]) . "\n";
PHP);

add_case($cases, 'object-statics', 'static-property-type-error', <<<'PHP'
class EdgeTypedStatic { public static int $count = 1; }
try {
    EdgeTypedStatic::$count = 'bad';
    echo "OK\n";
} catch (Throwable $e) {
    echo 'ERR:' . $e::class . "\n";
}
PHP);

add_case($cases, 'clone', 'clone-magic-method-deep-copy', <<<'PHP'
class EdgeBox { public function __construct(public int $n) {} }
class EdgeHolder {
    public function __construct(public EdgeBox $box) {}
    public function __clone() { $this->box = clone $this->box; $this->box->n++; }
}
$a = new EdgeHolder(new EdgeBox(3));
$b = clone $a;
$b->box->n += 10;
echo json_encode([$a->box->n, $b->box->n]) . "\n";
PHP);

add_case($cases, 'clone', 'clone-private-property-state', <<<'PHP'
class EdgePrivateClone {
    private int $n = 2;
    public function bump(): int { return ++$this->n; }
}
$a = new EdgePrivateClone();
$b = clone $a;
echo json_encode([$a->bump(), $b->bump(), $a->bump(), $b->bump()]) . "\n";
PHP);

add_case($cases, 'closures', 'closure-by-reference-capture', <<<'PHP'
$count = 1;
$inc = function () use (&$count): int { return ++$count; };
$count = 10;
echo json_encode([$inc(), $count, $inc()]) . "\n";
PHP);

add_case($cases, 'closures', 'closure-this-binding', <<<'PHP'
class EdgeClosureThis {
    public int $n = 4;
    public function make(): Closure { return fn (int $x): int => $this->n + $x; }
}
$fn = (new EdgeClosureThis())->make();
echo json_encode($fn(6)) . "\n";
PHP);

add_case($cases, 'exceptions', 'exception-catch-order-finally', <<<'PHP'
$trace = [];
try {
    try {
        throw new RuntimeException('edge');
    } finally {
        $trace[] = 'finally';
    }
} catch (InvalidArgumentException $e) {
    $trace[] = 'invalid';
} catch (RuntimeException $e) {
    $trace[] = 'runtime';
}
echo json_encode($trace) . "\n";
PHP);

add_case($cases, 'exceptions', 'exception-typeerror-normalized', <<<'PHP'
function edgeNeedsInt(int $n): int { return $n + 1; }
try {
    echo edgeNeedsInt('nope') . "\n";
} catch (Throwable $e) {
    echo 'ERR:' . $e::class . "\n";
}
PHP);

add_case($cases, 'include-scope', 'include-local-scope-and-return', <<<'PHP'
$path = __DIR__ . '/edge-include-local.php';
file_put_contents($path, <<<'INC'
<?php
$local += 2;
return $local * 3;
INC);
$local = 5;
$result = include $path;
unlink($path);
echo json_encode(['local' => $local, 'result' => $result]) . "\n";
PHP);

add_case($cases, 'include-scope', 'include-once-single-execution', <<<'PHP'
$path = __DIR__ . '/edge-include-once.php';
file_put_contents($path, <<<'INC'
<?php
$GLOBALS['edge_once_count'] = ($GLOBALS['edge_once_count'] ?? 0) + 1;
return $GLOBALS['edge_once_count'];
INC);
$edge_once_count = 0;
$a = include_once $path;
$b = include_once $path;
unlink($path);
echo json_encode([$a, $b, $edge_once_count]) . "\n";
PHP);

add_case($cases, 'callbacks', 'callback-static-method-and-closure', <<<'PHP'
class EdgeCallback { public static function twice(int $n): int { return $n * 2; } }
$values = array_map([EdgeCallback::class, 'twice'], [2, 4, 6]);
$sum = array_reduce($values, fn (int $carry, int $value): int => $carry + $value, 0);
echo json_encode(['values' => $values, 'sum' => $sum]) . "\n";
PHP);

add_case($cases, 'callbacks', 'callback-invalid-normalized', <<<'PHP'
try {
    call_user_func('definitely_missing_edge_callback');
    echo "OK\n";
} catch (Throwable $e) {
    echo 'ERR:' . $e::class . "\n";
}
PHP);

add_case($cases, 'typed-properties', 'typed-property-uninitialized-read', <<<'PHP'
class EdgeUninitTyped { public int $n; }
try {
    $x = (new EdgeUninitTyped())->n;
    echo json_encode($x) . "\n";
} catch (Throwable $e) {
    echo 'ERR:' . $e::class . "\n";
}
PHP);

add_case($cases, 'typed-properties', 'typed-property-assignment-error', <<<'PHP'
class EdgeTypedProperty { public int $n = 1; }
$o = new EdgeTypedProperty();
try {
    $o->n = 'bad';
    echo "OK\n";
} catch (Throwable $e) {
    echo 'ERR:' . $e::class . "\n";
}
PHP);

add_case($cases, 'filesystem-resources', 'filesystem-temp-stream-roundtrip', <<<'PHP'
$path = tempnam(sys_get_temp_dir(), 'jinx-edge-');
$handle = fopen($path, 'wb+');
fwrite($handle, 'abc');
rewind($handle);
$data = fread($handle, 3);
fclose($handle);
unlink($path);
echo json_encode(['data' => $data, 'exists' => file_exists($path)]) . "\n";
PHP);

add_case($cases, 'filesystem-resources', 'filesystem-missing-file-suppressed', <<<'PHP'
$path = __DIR__ . '/definitely-missing-edge-file.txt';
$result = @file_get_contents($path);
echo json_encode(['type' => gettype($result), 'value' => $result]) . "\n";
PHP);

$areas = [];
foreach ($cases as $case) {
    $areas[$case['area']] = true;
}

if (count($areas) !== 10) {
    fail('expected exactly 10 semantic areas, found ' . count($areas));
}
if (count($cases) !== 20) {
    fail('expected exactly 20 high-value edge fixtures, found ' . count($cases));
}

$checked = 0;
$mismatches = [];
foreach ($cases as $name => $case) {
    $fixture = $caseDir . '/' . $name . '.php';
    file_put_contents($fixture, $case['source']);

    $phpResult = run_process([$php, $fixture]);
    $jinxResult = run_process([$jinx, $fixture]);

    if ($jinxResult !== $phpResult) {
        $mismatches[] = [
            'name' => $name,
            'area' => $case['area'],
            'php' => $phpResult,
            'jinx' => $jinxResult,
        ];
    }

    $checked++;
}

if ($checked !== 20) {
    fail('expected to check exactly 20 high-value edge fixtures, checked ' . $checked);
}

echo 'STRICT NATIVE PARITY: ' . ($checked - count($mismatches)) . '/' . $checked . ' fixtures across ' . count($areas) . ' semantic areas' . PHP_EOL;
if ($mismatches !== []) {
    foreach ($mismatches as $mismatch) {
        fwrite(STDERR, "MISMATCH: {$mismatch['name']} ({$mismatch['area']})" . PHP_EOL);
        fwrite(STDERR, "PHP exit={$mismatch['php']['exit']} stdout=" . json_encode($mismatch['php']['stdout']) . PHP_EOL);
        fwrite(STDERR, "JINX exit={$mismatch['jinx']['exit']} stdout=" . json_encode($mismatch['jinx']['stdout']) . PHP_EOL);
        if ($mismatch['php']['stderr'] !== '') {
            fwrite(STDERR, "PHP stderr=" . json_encode($mismatch['php']['stderr']) . PHP_EOL);
        }
        if ($mismatch['jinx']['stderr'] !== '') {
            fwrite(STDERR, "JINX stderr=" . json_encode($mismatch['jinx']['stderr']) . PHP_EOL);
        }
    }

    fail('first-wave high-value edge fixture mismatches=' . count($mismatches) . ' of 20');
}

ksort($areas);
echo 'PASS: PHP vs JINX high-value edge fixtures checked 20 fixtures across 10 semantic areas: ' . implode(', ', array_keys($areas)) . PHP_EOL;
