<?php
declare(strict_types=1);

/**
 * Loop-core conformance harness.
 *
 * This test establishes the Oracle-shaped loop contract before the full parser
 * lowers every PHP loop form. Each fixture has:
 * - real PHP behavior,
 * - canonical Oracle-shaped operations,
 * - a tiny PASM-like evaluator result.
 */

function php_output(string $source): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'jinx-loop-');
    file_put_contents($tmp, $source);
    exec('php ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
    unlink($tmp);
    if ($code !== 0) {
        throw new RuntimeException("fixture PHP failed:\n" . implode("\n", $out));
    }
    return implode("\n", $out);
}

/** @param array<int,array<string,mixed>> $program */
function eval_loop_pasm(array $program): string
{
    $labels = [];
    foreach ($program as $i => $op) {
        if (($op['op'] ?? null) === 'LABEL') {
            $labels[$op['name']] = $i;
        }
    }

    $vars = [];
    $out = '';
    $pc = 0;
    $guard = 0;
    while ($pc < count($program)) {
        if (++$guard > 10000) {
            throw new RuntimeException('loop PASM guard tripped');
        }
        $op = $program[$pc];
        switch ($op['op']) {
            case 'LABEL':
                $pc++;
                break;
            case 'SET':
                $vars[$op['var']] = $op['value'];
                $pc++;
                break;
            case 'SET_ARRAY':
                $vars[$op['var']] = $op['value'];
                $pc++;
                break;
            case 'INC':
                $vars[$op['var']]++;
                $pc++;
                break;
            case 'ADD':
                $vars[$op['var']] += value_of($op['value'], $vars);
                $pc++;
                break;
            case 'ECHO':
                $out .= (string)value_of($op['value'], $vars);
                $pc++;
                break;
            case 'JMP':
                $pc = $labels[$op['target']] ?? throw new RuntimeException('unknown label ' . $op['target']);
                break;
            case 'JMP_IF_FALSE':
                $pc = eval_condition($op['condition'], $vars) ? $pc + 1 : ($labels[$op['target']] ?? throw new RuntimeException('unknown label ' . $op['target']));
                break;
            case 'JMP_IF_TRUE':
                $pc = eval_condition($op['condition'], $vars) ? ($labels[$op['target']] ?? throw new RuntimeException('unknown label ' . $op['target'])) : $pc + 1;
                break;
            case 'ITER_INIT':
                $array = $vars[$op['source']] ?? [];
                $vars[$op['iter']] = ['array' => array_values($array), 'index' => 0];
                $pc++;
                break;
            case 'ITER_VALID':
                $iter = $vars[$op['iter']];
                $vars[$op['target']] = $iter['index'] < count($iter['array']);
                $pc++;
                break;
            case 'ITER_VALUE':
                $iter = $vars[$op['iter']];
                $vars[$op['target']] = $iter['array'][$iter['index']];
                $pc++;
                break;
            case 'ITER_NEXT':
                $vars[$op['iter']]['index']++;
                $pc++;
                break;
            case 'ITER_FREE':
                unset($vars[$op['iter']]);
                $pc++;
                break;
            case 'END':
                return $out;
            default:
                throw new RuntimeException('unsupported loop PASM op ' . ($op['op'] ?? '<missing>'));
        }
    }
    return $out;
}

/** @param array<string,mixed> $vars */
function value_of(mixed $value, array $vars): mixed
{
    if (is_array($value) && ($value['var'] ?? null)) {
        return $vars[$value['var']] ?? 0;
    }
    return $value;
}

/** @param array<string,mixed> $vars */
function eval_condition(array $condition, array $vars): bool
{
    $left = value_of($condition['left'], $vars);
    $right = value_of($condition['right'], $vars);
    return match ($condition['op']) {
        '<' => $left < $right,
        '<=' => $left <= $right,
        '>' => $left > $right,
        '>=' => $left >= $right,
        '==' => $left == $right,
        '!=' => $left != $right,
        default => throw new RuntimeException('unsupported condition op ' . $condition['op']),
    };
}

$fixtures = [
    'while' => [
        'php' => <<<'PHP'
<?php
$i = 0;
while ($i < 3) {
    echo $i;
    $i++;
}
PHP,
        'pasm' => [
            ['op' => 'SET', 'var' => 'i', 'value' => 0],
            ['op' => 'LABEL', 'name' => 'while.start'],
            ['op' => 'JMP_IF_FALSE', 'condition' => ['left' => ['var' => 'i'], 'op' => '<', 'right' => 3], 'target' => 'while.end'],
            ['op' => 'ECHO', 'value' => ['var' => 'i']],
            ['op' => 'INC', 'var' => 'i'],
            ['op' => 'JMP', 'target' => 'while.start'],
            ['op' => 'LABEL', 'name' => 'while.end'],
            ['op' => 'END'],
        ],
    ],
    'for-break-continue' => [
        'php' => <<<'PHP'
<?php
for ($i = 0; $i < 6; $i++) {
    if ($i == 1) { continue; }
    if ($i == 4) { break; }
    echo $i;
}
PHP,
        'pasm' => [
            ['op' => 'SET', 'var' => 'i', 'value' => 0],
            ['op' => 'LABEL', 'name' => 'for.start'],
            ['op' => 'JMP_IF_FALSE', 'condition' => ['left' => ['var' => 'i'], 'op' => '<', 'right' => 6], 'target' => 'for.end'],
            ['op' => 'JMP_IF_TRUE', 'condition' => ['left' => ['var' => 'i'], 'op' => '==', 'right' => 1], 'target' => 'for.continue'],
            ['op' => 'JMP_IF_TRUE', 'condition' => ['left' => ['var' => 'i'], 'op' => '==', 'right' => 4], 'target' => 'for.end'],
            ['op' => 'ECHO', 'value' => ['var' => 'i']],
            ['op' => 'LABEL', 'name' => 'for.continue'],
            ['op' => 'INC', 'var' => 'i'],
            ['op' => 'JMP', 'target' => 'for.start'],
            ['op' => 'LABEL', 'name' => 'for.end'],
            ['op' => 'END'],
        ],
    ],
    'foreach-array' => [
        'php' => <<<'PHP'
<?php
$items = [2, 3, 4];
foreach ($items as $value) {
    echo $value;
}
PHP,
        'pasm' => [
            ['op' => 'SET_ARRAY', 'var' => 'items', 'value' => [2, 3, 4]],
            ['op' => 'ITER_INIT', 'iter' => 'it0', 'source' => 'items'],
            ['op' => 'LABEL', 'name' => 'foreach.start'],
            ['op' => 'ITER_VALID', 'iter' => 'it0', 'target' => 'iter_valid'],
            ['op' => 'JMP_IF_FALSE', 'condition' => ['left' => ['var' => 'iter_valid'], 'op' => '==', 'right' => true], 'target' => 'foreach.end'],
            ['op' => 'ITER_VALUE', 'iter' => 'it0', 'target' => 'value'],
            ['op' => 'ECHO', 'value' => ['var' => 'value']],
            ['op' => 'LABEL', 'name' => 'foreach.continue'],
            ['op' => 'ITER_NEXT', 'iter' => 'it0'],
            ['op' => 'JMP', 'target' => 'foreach.start'],
            ['op' => 'LABEL', 'name' => 'foreach.end'],
            ['op' => 'ITER_FREE', 'iter' => 'it0'],
            ['op' => 'END'],
        ],
    ],
];

foreach ($fixtures as $name => $fixture) {
    $php = php_output($fixture['php']);
    $pasm = eval_loop_pasm($fixture['pasm']);
    if ($php !== $pasm) {
        throw new RuntimeException("{$name} parity failed: PHP={$php} PASM={$pasm}");
    }
}

echo 'PASS: Oracle-shaped loop PASM parity fixtures passed for while, for/break/continue, and foreach array' . "\n";
