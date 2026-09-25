<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$commands = [
    [
        'name' => 'simple add',
        'cmd' => sprintf(
            'php %s %s 2>&1',
            escapeshellarg($root . '/scripts/jinx-run.php'),
            escapeshellarg($root . '/fixtures/simple-add.php')
        ),
        'expected' => "5\n",
    ],
    [
        'name' => 'strlen',
        'cmd' => sprintf(
            'php %s %s 2>&1',
            escapeshellarg($root . '/scripts/jinx-run.php'),
            escapeshellarg($root . '/fixtures/strlen.php')
        ),
        'expected' => "6\n",
    ],
    [
        'name' => 'debug jinx/pasm',
        'cmd' => sprintf(
            'php %s --show-jinx --show-pasm %s 2>&1',
            escapeshellarg($root . '/scripts/jinx-run.php'),
            escapeshellarg($root . '/fixtures/simple-add.php')
        ),
        'contains' => [
            '=== .jinx ===',
            'assign x int 2',
            'assign y int 3',
            'return add local x local y',
            '=== PASM chain ===',
            'ADD',
            'RET',
            "5\n",
        ],
    ],
];

foreach ($commands as $case) {
    $output = [];
    $code = 0;

    exec($case['cmd'], $output, $code);

    $text = implode(PHP_EOL, $output) . PHP_EOL;

    if ($code !== 0) {
        fwrite(STDERR, "FAIL: {$case['name']} exited {$code}\n{$text}");
        exit(1);
    }

    if (isset($case['expected']) && $text !== $case['expected']) {
        fwrite(STDERR, "FAIL: {$case['name']} output mismatch\n");
        fwrite(STDERR, "Expected:\n{$case['expected']}\nActual:\n{$text}\n");
        exit(1);
    }

    foreach (($case['contains'] ?? []) as $needle) {
        if (!str_contains($text, $needle)) {
            fwrite(STDERR, "FAIL: {$case['name']} missing {$needle}\n{$text}");
            exit(1);
        }
    }
}

echo "PASS: jinx-run CLI executes PHP source through .jinx and PASM\n";
