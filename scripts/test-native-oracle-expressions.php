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
    'ternary' => 'return $x ? $y + 3 : $y - 4;',
    'shorthand-ternary' => 'return $x ?: $y;',
    'nested-ternary' => 'return $x ? ($y ? 7 : 8) : 9;',
    'lazy-ternary' => 'return $x ? 7 : ($x + 9223372036854775807);',
    'mixed-ternary-associativity' => 'return ($x ?: $y) ? 2 : 3;',
    'chained-shorthand' => 'return $x ?: $y ?: 9;',
];
foreach ($cases as $name => $body) {
    $path = $directory . '/expression-' . $name . '.php';
    $target = $directory . '/expression-' . $name . '.jxo';
    file_put_contents($path, '<?php ' . $body);
    $compile = integerVmRun([PHP_BINARY, $root . '/scripts/compile-oracle-native.php', $path, $target]);
    if ($compile[0]) throw new RuntimeException($name . ': ' . json_encode($compile));
    preg_match_all('/^INPUT \d+ (\w+)$/m', file_get_contents($target), $names);
    foreach ([[20, 7], [-5, 2], [0, -9], [9007199254740993, 1], [1, 0], [0, 0]] as [$x, $y]) {
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
    'declare(ticks=1); return 2;', 'return $_GET;', 'return 01;', 'return 9223372036854775808;',
    'return $x ?: $y ? 2 : 3;', 'return $x ? 1 : $y ? 2 : 3;'] as $body) {
    $path = $directory . '/expression-rejected.php';
    file_put_contents($path, '<?php ' . $body);
    if (!integerVmRun([PHP_BINARY, $root . '/scripts/compile-oracle-native.php', $path, $directory . '/expression-rejected.jxo'])[0])
        throw new RuntimeException('Unsupported expression was accepted: ' . $body);
}
$invalidBranches = [
    "JXOR_INT_2\n2 1 4\nINPUT 0 x\nJZ 0 2\nCONST 1 7\nMOVE 0 1\nRETURN_ADD 0 0\n",
    "JXOR_INT_2\n1 1 2\nINPUT 0 x\nJZ 0 0\nRETURN_ADD 0 0\n",
    "JXOR_INT_2\n1 1 2\nINPUT 0 x\nJZ 0 2\nRETURN_ADD 0 0\n",
    "JXOR_INT_1\n1 1 2\nINPUT 0 x\nJZ 0 1\nRETURN_ADD 0 0\n",
];
$overflowSource = $directory . '/branch-overflow.php';
$overflowArtifact = $directory . '/branch-overflow.jxo';
file_put_contents($overflowSource, '<?php return $x ? (9223372036854775807 + 1) : 7;');
if (integerVmRun([PHP_BINARY, $root . '/scripts/compile-oracle-native.php', $overflowSource, $overflowArtifact])[0])
    throw new RuntimeException('Lazy overflow fixture failed compilation');
$skipped = integerVmRun([$binary, $overflowArtifact, 'x=0'], true);
if ($skipped[0] || json_decode($skipped[1], true)['return'] !== 7)
    throw new RuntimeException('Unselected branch was evaluated');
if (!integerVmRun([$binary, $overflowArtifact, 'x=1'], true)[0])
    throw new RuntimeException('Selected branch overflow was silently accepted');
foreach ($invalidBranches as $contents) {
    file_put_contents($directory . '/invalid-branch.jxo', $contents);
    if (!integerVmRun([$binary, $directory . '/invalid-branch.jxo', 'x=1'], true)[0])
        throw new RuntimeException('Invalid branch artifact accepted');
}
echo 'PASS: structured native Oracle expressions, ' . count($cases) . " families with six runtime input sets\n";
