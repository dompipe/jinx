<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/examples/jinx-math-shell.php';

$iterations = isset($argv[1]) ? max(1, (int)$argv[1]) : 10000;
$vars = [];
$lines = [
    'a = 6',
    'b = 7',
    '$a * $b',
    '($a + $b) * 3 - 4',
    'pow(2, 10)',
    'sqrt(144) + max($a, $b, 50)',
    'a = $a + 1',
];

$start = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    foreach ($lines as $line) {
        jinx_math_eval_line($line, $vars);
    }
}
$elapsedNs = hrtime(true) - $start;
$evaluations = $iterations * count($lines);
$seconds = $elapsedNs / 1_000_000_000;
$perSecond = $evaluations / max($seconds, 0.000001);

echo json_encode([
    'iterations' => $iterations,
    'evaluations' => $evaluations,
    'seconds' => round($seconds, 6),
    'evaluations_per_second' => round($perSecond, 2),
    'final_vars' => $vars,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
