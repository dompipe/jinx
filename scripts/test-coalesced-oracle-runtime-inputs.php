<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/CoalescedOracleCompiler.php';

use jinx\oracle\CoalescedOracleCompiler;

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

$cases = [
    [
        'label' => 'runtime add',
        'jinx' => "return add local x local y\n",
        'expectedOps' => [[
            'op' => 'ORET_ADD_LOCAL',
            'left' => 'LOCAL:x',
            'right' => 'LOCAL:y',
        ]],
        'locals' => [
            'LOCAL:x' => 20,
            'LOCAL:y' => 7,
        ],
        'expected' => 27,
    ],
    [
        'label' => 'runtime sub',
        'jinx' => "return sub local x local y\n",
        'expectedOps' => [[
            'op' => 'ORET_SUB_LOCAL',
            'left' => 'LOCAL:x',
            'right' => 'LOCAL:y',
        ]],
        'locals' => [
            'LOCAL:x' => 20,
            'LOCAL:y' => 7,
        ],
        'expected' => 13,
    ],
    [
        'label' => 'runtime mul',
        'jinx' => "return mul local x local y\n",
        'expectedOps' => [[
            'op' => 'ORET_MUL_LOCAL',
            'left' => 'LOCAL:x',
            'right' => 'LOCAL:y',
        ]],
        'locals' => [
            'LOCAL:x' => 20,
            'LOCAL:y' => 7,
        ],
        'expected' => 140,
    ],
    [
        'label' => 'runtime strlen',
        'jinx' => "return builtin strlen local s\n",
        'expectedOps' => [[
            'op' => 'ORET_BUILTIN_LOCAL',
            'builtin' => 'strlen',
            'arg' => 'LOCAL:s',
        ]],
        'locals' => [
            'LOCAL:s' => 'oracle',
        ],
        'expected' => 6,
    ],
];

foreach ($cases as $case) {
    $ops = CoalescedOracleCompiler::compileJinx($case['jinx']);

    same($ops, $case['expectedOps'], $case['label'] . ' preserves coalesced Oracle op');

    try {
        CoalescedOracleCompiler::execute($ops);
        fail($case['label'] . ' without locals should fail');
    } catch (Throwable $e) {
        if (!str_contains($e->getMessage(), 'Missing runtime local')) {
            fail($case['label'] . ' failed for wrong reason: ' . $e->getMessage());
        }
    }

    $result = CoalescedOracleCompiler::execute($ops, $case['locals']);
    same($result, $case['expected'], $case['label'] . ' executes with runtime locals');
}

echo "PASS: coalesced Oracle executes dynamic runtime locals for add/sub/mul/strlen" . PHP_EOL;
