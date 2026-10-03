<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$php = PHP_BINARY;
$caseDir = $root . '/build/differential/php-vs-jinx-high-value-edge-fixtures-two';

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

    $process = proc_open($command, $descriptorSpec, $pipes);
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
        'source' => "<?php\n\ndeclare(strict_types=1);\n\n" . $body . "\n",
    ];
}

if (!is_file($jinx) || !is_executable($jinx)) {
    fail('repository-root native ./jinx missing or not executable; run ./scripts/build-native-jinx.sh first');
}

if (!is_dir($caseDir) && !mkdir($caseDir, 0777, true) && !is_dir($caseDir)) {
    fail('could not create second high-value edge fixture directory: ' . $caseDir);
}

$cases = [];

add_case($cases, 'generators', 'generator-send-and-return', <<<'PHP'
function edgeGeneratorSend(): Generator
{
    $incoming = yield 'ready';
    yield $incoming * 2;
    return $incoming * 3;
}
$g = edgeGeneratorSend();
$first = $g->current();
$second = $g->send(5);
$g->next();
echo json_encode(['first' => $first, 'second' => $second, 'return' => $g->getReturn()]) . "\n";
PHP);

add_case($cases, 'generators', 'generator-yield-from-return', <<<'PHP'
function edgeInnerGenerator(): Generator
{
    yield 'a' => 1;
    yield 'b' => 2;
    return 7;
}
function edgeOuterGenerator(): Generator
{
    $ret = yield from edgeInnerGenerator();
    yield 'c' => $ret + 1;
    return $ret + 2;
}
$g = edgeOuterGenerator();
$values = iterator_to_array($g);
echo json_encode(['values' => $values, 'return' => $g->getReturn()]) . "\n";
PHP);

add_case($cases, 'variadics-named-arguments', 'variadic-unpack-order', <<<'PHP'
function edgeVariadic(int $head, int ...$tail): array
{
    return [$head, $tail, array_sum($tail)];
}
$args = [2, 3, 4, 5];
echo json_encode(edgeVariadic(...$args)) . "\n";
PHP);

add_case($cases, 'variadics-named-arguments', 'named-argument-reorder-and-error', <<<'PHP'
function edgeNamed(int $a, int $b = 2, int $c = 3): array
{
    return [$a, $b, $c];
}
$ok = edgeNamed(c: 9, a: 1);
try {
    edgeNamed(a: 1, missing: 4);
    $err = 'OK';
} catch (Throwable $e) {
    $err = 'ERR:' . $e::class;
}
echo json_encode(['ok' => $ok, 'error' => $err]) . "\n";
PHP);

add_case($cases, 'destructuring-spread', 'array-nested-destructuring', <<<'PHP'
[$a, [$b, $c], 'name' => $name] = [10, [20, 30], 'name' => 'jinx'];
echo json_encode([$a, $b, $c, $name]) . "\n";
PHP);

add_case($cases, 'destructuring-spread', 'array-spread-string-and-int-keys', <<<'PHP'
$left = [0 => 'zero', 'a' => 1];
$right = ['a' => 2, 'b' => 3, 9 => 'nine'];
$out = [...$left, ...$right, 'c' => 4];
echo json_encode($out) . "\n";
PHP);

add_case($cases, 'nullsafe-coalescing', 'nullsafe-chain-fallback', <<<'PHP'
class EdgeNode
{
    public function __construct(public ?EdgeNode $child = null, public ?string $value = null) {}
}
$a = new EdgeNode(new EdgeNode(null, 'leaf'));
$b = null;
echo json_encode([
    $a?->child?->value ?? 'fallback',
    $b?->child?->value ?? 'fallback',
]) . "\n";
PHP);

add_case($cases, 'nullsafe-coalescing', 'coalesce-assignment-array-object', <<<'PHP'
$data = [];
$data['n'] ??= 4;
$data['n'] ??= 9;
$o = new stdClass();
$o->value ??= 6;
$o->value ??= 11;
echo json_encode([$data, $o->value]) . "\n";
PHP);

add_case($cases, 'late-static-binding', 'late-static-property-and-class', <<<'PHP'
class EdgeLateBase
{
    public static string $name = 'base';
    public static function who(): array
    {
        return [static::class, static::$name, self::$name];
    }
}
class EdgeLateChild extends EdgeLateBase
{
    public static string $name = 'child';
}
echo json_encode([EdgeLateBase::who(), EdgeLateChild::who()]) . "\n";
PHP);

add_case($cases, 'late-static-binding', 'late-static-parent-factory', <<<'PHP'
class EdgeFactoryBase
{
    public static function make(): static
    {
        return new static();
    }
}
class EdgeFactoryChild extends EdgeFactoryBase
{
    public static function throughParent(): static
    {
        return parent::make();
    }
}
echo json_encode([
    EdgeFactoryBase::make()::class,
    EdgeFactoryChild::make()::class,
    EdgeFactoryChild::throughParent()::class,
]) . "\n";
PHP);

add_case($cases, 'magic-methods', 'magic-property-overloading', <<<'PHP'
class EdgeMagicProperty
{
    private array $data = [];
    public function __set(string $name, mixed $value): void { $this->data[$name] = $value; }
    public function __get(string $name): mixed { return $this->data[$name] ?? null; }
    public function __isset(string $name): bool { return isset($this->data[$name]); }
    public function __unset(string $name): void { unset($this->data[$name]); }
}
$o = new EdgeMagicProperty();
$o->x = 7;
$before = [isset($o->x), $o->x];
unset($o->x);
echo json_encode([$before, isset($o->x), $o->x]) . "\n";
PHP);

add_case($cases, 'magic-methods', 'magic-call-and-callstatic', <<<'PHP'
class EdgeMagicCall
{
    public function __call(string $name, array $args): array
    {
        return ['instance', $name, $args];
    }
    public static function __callStatic(string $name, array $args): array
    {
        return ['static', $name, $args];
    }
}
$o = new EdgeMagicCall();
echo json_encode([$o->missing(1, 2), EdgeMagicCall::unknown('x')]) . "\n";
PHP);

add_case($cases, 'readonly', 'readonly-class-mutation-error', <<<'PHP'
readonly class EdgeReadonlyClass
{
    public function __construct(public int $n) {}
}
$o = new EdgeReadonlyClass(4);
try {
    $o->n = 9;
    $err = 'OK';
} catch (Throwable $e) {
    $err = 'ERR:' . $e::class;
}
echo json_encode([$o->n, $err]) . "\n";
PHP);

add_case($cases, 'readonly', 'readonly-shallow-object-and-reassign-error', <<<'PHP'
class EdgeReadonlyHolder
{
    public readonly stdClass $box;
    public function __construct()
    {
        $this->box = (object) ['n' => 1];
    }
}
$o = new EdgeReadonlyHolder();
$o->box->n = 8;
try {
    $o->box = (object) ['n' => 9];
    $err = 'OK';
} catch (Throwable $e) {
    $err = 'ERR:' . $e::class;
}
echo json_encode([$o->box->n, $err]) . "\n";
PHP);

add_case($cases, 'enums', 'backed-enum-from-tryfrom', <<<'PHP'
enum EdgeStatus: string
{
    case Ready = 'ready';
    case Done = 'done';
}
$a = EdgeStatus::from('ready');
$b = EdgeStatus::tryFrom('missing');
echo json_encode([$a->name, $a->value, $b?->name]) . "\n";
PHP);

add_case($cases, 'enums', 'unit-enum-match-name', <<<'PHP'
enum EdgeDirection
{
    case North;
    case South;
}
$direction = EdgeDirection::South;
$label = match ($direction) {
    EdgeDirection::North => 'N',
    EdgeDirection::South => 'S',
};
echo json_encode([$direction->name, $label, count(EdgeDirection::cases())]) . "\n";
PHP);

add_case($cases, 'namespaces', 'namespace-use-class-function-const', <<<'PHP'
namespace EdgeNsA {
    const FLAG = 'A';
    function ping(string $value): string { return 'ping:' . $value; }
    class Box { public function label(): string { return 'box'; } }
}
namespace EdgeNsB {
    use EdgeNsA\Box as ImportedBox;
    use function EdgeNsA\ping;
    use const EdgeNsA\FLAG;

    $box = new ImportedBox();
    echo json_encode([$box->label(), ping('x'), FLAG]) . "\n";
}
PHP);

add_case($cases, 'namespaces', 'namespace-function-resolution', <<<'PHP'
namespace EdgeNsC {
    function strlen(string $value): int { return 99; }
    echo json_encode([strlen('x'), \strlen('abc')]) . "\n";
}
PHP);

add_case($cases, 'union-nullable-types', 'union-and-nullable-success', <<<'PHP'
function edgeUnionValue(int|string $value): int|string
{
    return $value;
}
function edgeNullable(bool $set): ?string
{
    return $set ? 'yes' : null;
}
echo json_encode([
    edgeUnionValue(7),
    edgeUnionValue('x'),
    edgeNullable(true),
    edgeNullable(false),
]) . "\n";
PHP);

add_case($cases, 'union-nullable-types', 'union-type-error-normalized', <<<'PHP'
function edgeStrictUnion(int|string $value): int|string
{
    return $value;
}
try {
    edgeStrictUnion([]);
    $err = 'OK';
} catch (Throwable $e) {
    $err = 'ERR:' . $e::class;
}
echo json_encode($err) . "\n";
PHP);

$areas = [];
foreach ($cases as $case) {
    $areas[$case['area']] = true;
}

if (count($areas) !== 10) {
    fail('expected exactly 10 second-wave semantic areas, found ' . count($areas));
}
if (count($cases) !== 20) {
    fail('expected exactly 20 second-wave high-value edge fixtures, found ' . count($cases));
}

$checked = 0;
foreach ($cases as $name => $case) {
    $fixture = $caseDir . '/' . $name . '.php';
    file_put_contents($fixture, $case['source']);

    $phpResult = run_process([$php, $fixture]);
    $jinxResult = run_process([$jinx, $fixture]);

    if ($jinxResult['exit'] !== $phpResult['exit']) {
        fail("{$name} ({$case['area']}) exit mismatch: PHP={$phpResult['exit']} JINX={$jinxResult['exit']}\nPHP stdout:\n{$phpResult['stdout']}\nJINX stdout:\n{$jinxResult['stdout']}\nPHP stderr:\n{$phpResult['stderr']}\nJINX stderr:\n{$jinxResult['stderr']}");
    }

    if ($jinxResult['stdout'] !== $phpResult['stdout']) {
        fail("{$name} ({$case['area']}) stdout mismatch\nPHP stdout:\n{$phpResult['stdout']}\nJINX stdout:\n{$jinxResult['stdout']}\nPHP stderr:\n{$phpResult['stderr']}\nJINX stderr:\n{$jinxResult['stderr']}");
    }

    $checked++;
}

if ($checked !== 20) {
    fail('expected to check exactly 20 second-wave high-value edge fixtures, checked ' . $checked);
}

ksort($areas);
echo 'PASS: PHP vs JINX high-value edge fixtures wave two checked 20 fixtures across 10 semantic areas: ' . implode(', ', array_keys($areas)) . PHP_EOL;
