<?php
declare(strict_types=1);

if (($argv[1] ?? '') === '--php-worker') {
    $iterations = 1000000;
    $checksum = 0;
    $program = static function (int $x, int $y): int { $sum = $x + $y; return $sum * $y; };
    for ($i = 0; $i < 10000; $i++) $program(20 + $i % 1024, 7);
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $result = $program(20 + $i % 1024, 7);
        $checksum += $result;
    }
    echo json_encode(['return' => $result, 'iterations' => $iterations, 'ns_per_execution' => (hrtime(true) - $start) / $iterations, 'checksum' => $checksum]), PHP_EOL;
    exit;
}
require __DIR__ . '/test-native-oracle-integer-vm.php';
$rows = [];
for ($i = 0; $i < 5; $i++) {
    $commands = [
        'php' => [PHP_BINARY, '-d', 'opcache.enable_cli=1', __FILE__, '--php-worker'],
        'native' => [$binary, $artifact, '--iterations=1000000', '--vary=x', 'x=20', 'y=7'],
    ];
    if ($i % 2) $commands = array_reverse($commands, true);
    $row = [];
    foreach ($commands as $engine => $command) {
        $run = integerVmRun($command, $engine === 'native');
        if ($run[0] || $run[2] !== '') throw new RuntimeException(json_encode($run));
        $row[$engine] = json_decode($run[1], true, 512, JSON_THROW_ON_ERROR);
    }
    if ($row['php']['return'] !== $row['native']['return'] || $row['php']['checksum'] !== $row['native']['checksum'])
        throw new RuntimeException('Benchmark result/checksum mismatch');
    $row['speedup'] = $row['php']['ns_per_execution'] / $row['native']['ns_per_execution'];
    $rows[] = $row;
    printf("Run %d: PHP %.2f ns, native Oracle %.2f ns, PHP/native %.2fx\n", $i + 1, $row['php']['ns_per_execution'], $row['native']['ns_per_execution'], $row['speedup']);
}
$ratios = array_column($rows, 'speedup');
sort($ratios);
$report = [
    'contract' => 'Compile once; varying integer runtime inputs; hot PHP function with CLI OPcache enabled; native instruction execution with PHP unavailable; process startup and compilation excluded.',
    'workload' => '$sum = $x + $y; return $sum * $y;',
    'iterations_per_sample' => 1000000,
    'php_version' => PHP_VERSION,
    'samples' => $rows,
    'median_speedup' => $ratios[2],
];
$output = $argv[1] ?? $root . '/build/oracle-integer/benchmark.json';
file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
printf("Median PHP/native speedup: %.2fx\n", $ratios[2]);
