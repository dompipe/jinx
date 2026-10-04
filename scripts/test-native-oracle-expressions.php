<?php
declare(strict_types=1);
require __DIR__ . '/test-native-oracle-integer-vm.php';

$cases = [
    'precedence' => 'return $x + $y * 3;',
    'grouping' => 'return ($x + 3) * -$y;',
    'associativity' => 'return $x - $y - 4;',
    'copy' => '$copy = $x; $x = 7; return $copy;',
    'alias-reassignment' => '$x = $y; $y = 3; return $x + $y;',
    'compound' => '$x += $y * 2; $x -= 3; $x *= -2; return $x;',
    'unary' => 'return -(-$x) + +$y;',
    'comments' => '$z = $x /* ; */ + $y; // trailing comment
return $z;',
    'constant' => 'declare(strict_types=1); return 42;',
];
foreach ($cases as $name => $body) {
    $path = $directory . '/expression-' . $name . '.php';
    $target = $directory . '/expression-' . $name . '.jxo';
    file_put_contents($path, '<?php ' . $body);
    $compile = integerVmRun([PHP_BINARY, $root . '/scripts/compile-oracle-native.php', $path, $target]);
    if ($compile[0]) throw new RuntimeException($name . ': ' . json_encode($compile));
    preg_match_all('/^INPUT \d+ (\w+)$/m', file_get_contents($target), $names);
    foreach ([[20, 7], [-5, 2], [0, -9], [9007199254740993, 1]] as [$x, $y]) {
        file_put_contents($directory . '/expression-reference.php', '<?php $x=' . $x . '; $y=' . $y . '; echo json_encode(require ' . var_export($path, true) . ');');
        $php = integerVmRun([PHP_BINARY, $directory . '/expression-reference.php']);
        $values = ['x' => $x, 'y' => $y];
        $args = array_map(static fn($name) => $name . '=' . $values[$name], $names[1]);
        $native = integerVmRun([$binary, $target, ...$args], true);
        if ($php[0] || $native[0] || $native[2] !== '' || json_decode($native[1], true)['return'] !== json_decode($php[1], true))
            throw new RuntimeException($name . ': ' . json_encode([$php, $native]));
    }
}
foreach (['return $x / 2;', 'return $x ** 2;', '$x =& $y; return $x;', 'return abs($x);',
    'declare(ticks=1); return 2;', 'return $_GET;', 'return 01;', 'return 9223372036854775808;'] as $body) {
    $path = $directory . '/expression-rejected.php';
    file_put_contents($path, '<?php ' . $body);
    if (!integerVmRun([PHP_BINARY, $root . '/scripts/compile-oracle-native.php', $path, $directory . '/expression-rejected.jxo'])[0])
        throw new RuntimeException('Unsupported expression was accepted: ' . $body);
}
echo 'PASS: structured native Oracle expressions, ' . count($cases) . " families with four runtime input sets\n";
