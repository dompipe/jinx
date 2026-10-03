<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';

use jinx\oracle\OracleProgramCompiler;

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function same(mixed $actual, mixed $expected, string $label): void
{
    if ($actual !== $expected) {
        fail($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/** @return array<string,mixed> */
function compile_modern_fixture(string $body): array
{
    $path = tempnam(sys_get_temp_dir(), 'jinx-modern-');
    if ($path === false) {
        fail('could not allocate modern PHP recording fixture');
    }

    file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\n" . $body . "\n");
    try {
        return OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($path);
    } finally {
        @unlink($path);
    }
}

/** @return list<array<string,mixed>> */
function statements_with_op(array $program, string $op): array
{
    return array_values(array_filter(
        $program['statements'] ?? [],
        static fn (array $statement): bool => ($statement['op'] ?? null) === $op
    ));
}

/** @return list<array<string,mixed>> */
function statements_with_feature(array $program, string $feature): array
{
    return array_values(array_filter(
        $program['statements'] ?? [],
        static fn (array $statement): bool => (bool) ($statement['features']->{$feature} ?? false)
    ));
}

$namespace = compile_modern_fixture(<<<'PHP'
namespace Edge\Modern {
    const FLAG = 1;
}
PHP);
same(count(statements_with_op($namespace, 'O_NAMESPACE')), 1, 'namespace block records O_NAMESPACE');
same(count(statements_with_feature($namespace, 'namespace_block')), 1, 'namespace block feature');

$enum = compile_modern_fixture(<<<'PHP'
enum EdgeStatus: string
{
    case Ready = 'ready';
    case Done = 'done';
}
PHP);
same(count(statements_with_op($enum, 'O_ENUM_DECL')), 1, 'enum declaration recorded');
$enumDecls = statements_with_op($enum, 'O_ENUM_DECL');
same($enumDecls[0]['features']->backing_type ?? null, 'string', 'backed enum type recorded');
same(count(statements_with_op($enum, 'O_ENUM_CASE')), 2, 'enum cases recorded separately');
$enumCases = statements_with_op($enum, 'O_ENUM_CASE');
same($enumCases[0]['features']->name ?? null, 'Ready', 'first enum case name');
same($enumCases[0]['features']->backed_value_source ?? null, "'ready'", 'first enum backed value source');

$yieldFrom = compile_modern_fixture(<<<'PHP'
function edgeGenerator(): Generator
{
    yield from [1, 2, 3];
}
PHP);
same(count(statements_with_op($yieldFrom, 'O_YIELD_FROM')), 1, 'yield from gets dedicated opcode');
same(count(statements_with_feature($yieldFrom, 'yield_from')), 1, 'yield from feature');

$coalesceAssign = compile_modern_fixture(<<<'PHP'
$data = [];
$data['n'] ??= 4;
PHP);
same(count(statements_with_op($coalesceAssign, 'O_COALESCE_ASSIGN')), 1, 'coalesce assignment gets dedicated opcode');
same(count(statements_with_feature($coalesceAssign, 'coalesce_assignment')), 1, 'coalesce assignment feature');

$destructure = compile_modern_fixture(<<<'PHP'
[$a, [$b, $c]] = [10, [20, 30]];
PHP);
same(count(statements_with_op($destructure, 'O_DESTRUCTURE_ASSIGN')), 1, 'destructuring assignment gets dedicated opcode');

$nullsafe = compile_modern_fixture(<<<'PHP'
class EdgeNode { public ?EdgeNode $child = null; }
$node = new EdgeNode();
$value = $node?->child;
PHP);
same(count(statements_with_op($nullsafe, 'O_NULLSAFE_PROPERTY_FETCH')), 1, 'nullsafe property fetch gets dedicated opcode');
same(count(statements_with_feature($nullsafe, 'nullsafe_operator')), 1, 'nullsafe feature');

$propertyAssign = compile_modern_fixture(<<<'PHP'
$o = new stdClass();
$o->value = 6;
PHP);
same(count(statements_with_op($propertyAssign, 'O_PROPERTY_ASSIGN')), 1, 'object property assignment is not mislabeled as fetch');

$staticCompound = compile_modern_fixture(<<<'PHP'
class EdgeCounter { public static int $count = 1; }
EdgeCounter::$count += 2;
PHP);
same(count(statements_with_op($staticCompound, 'O_STATIC_PROPERTY_ASSIGN')), 1, 'compound static property assignment uses write opcode');
$staticWrites = statements_with_op($staticCompound, 'O_STATIC_PROPERTY_ASSIGN');
same($staticWrites[0]['features']->compound_assignment ?? null, true, 'compound static property assignment feature');

$arguments = compile_modern_fixture(<<<'PHP'
function edgeArgs(int $head, int ...$tail): array { return $tail; }
$args = [1, 2, 3];
$out = edgeArgs(head: 1, ...$args);
PHP);
same(count(statements_with_feature($arguments, 'named_argument')), 1, 'named argument feature');
if (count(statements_with_feature($arguments, 'argument_unpack')) < 1) {
    fail('argument unpack feature missing');
}
$functionDecls = statements_with_op($arguments, 'O_FUNCTION_DECL');
same($functionDecls[0]['features']->variadic_parameter ?? null, true, 'variadic parameter feature');

$spread = compile_modern_fixture(<<<'PHP'
$left = [1, 2];
$out = [...$left, 3];
PHP);
same(count(statements_with_feature($spread, 'array_spread')), 1, 'array spread feature');

$lateStatic = compile_modern_fixture(<<<'PHP'
class EdgeBase
{
    public static string $name = 'base';
    public static function who(): array
    {
        return [static::class, static::$name, self::$name];
    }
}
PHP);
if (count(statements_with_feature($lateStatic, 'late_static_binding')) < 1) {
    fail('late static binding feature missing');
}

$readonly = compile_modern_fixture(<<<'PHP'
readonly class EdgeReadonly
{
    public function __construct(public int $n) {}
}
PHP);
if (count(statements_with_feature($readonly, 'readonly_semantics')) < 1) {
    fail('readonly semantics feature missing');
}

$types = compile_modern_fixture(<<<'PHP'
function edgeTypes(int|string $value): ?string
{
    return is_int($value) ? (string) $value : null;
}
PHP);
$typedFunctions = statements_with_op($types, 'O_FUNCTION_DECL');
same($typedFunctions[0]['features']->union_parameter_type ?? null, true, 'union parameter type feature');
same($typedFunctions[0]['features']->nullable_return_type ?? null, true, 'nullable return type feature');
if (count(statements_with_feature($types, 'union_type')) < 1) {
    fail('union type semantic feature missing');
}
if (count(statements_with_feature($types, 'nullable_type')) < 1) {
    fail('nullable type semantic feature missing');
}

$properties = compile_modern_fixture(<<<'PHP'
class EdgeCompositeProperties
{
    public int|string $union = 1;
    public readonly ?stdClass $nullable;
}
PHP);
$propertyDecls = statements_with_op($properties, 'O_PROPERTY_DECL');
if (count($propertyDecls) < 2) {
    fail('composite typed properties were not recorded as property declarations');
}
same($propertyDecls[0]['features']->declared_type ?? null, 'int|string', 'union property declared type');
same($propertyDecls[0]['features']->union_property_type ?? null, true, 'union property type feature');
same($propertyDecls[1]['features']->declared_type ?? null, '?stdClass', 'nullable property declared type');
same($propertyDecls[1]['features']->nullable_property_type ?? null, true, 'nullable property type feature');

$imports = compile_modern_fixture(<<<'PHP'
namespace Edge\Imports;
use function strlen;
use const PHP_VERSION;
PHP);
same(count(statements_with_feature($imports, 'use_function')), 1, 'use function feature');
same(count(statements_with_feature($imports, 'use_const')), 1, 'use const feature');

echo "PASS: Oracle modern PHP recorder distinguishes high-risk PHP 8+ semantic constructs without claiming execution" . PHP_EOL;
