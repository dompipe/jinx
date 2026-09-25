<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/examples/jinx-math-shell.php';

function php_math_expected(string $expr, array $vars): string
{
    return jinx_math_format(jinx_math_eval_php_expression($expr, $vars));
}

$vars = [];
$cases = [
    ['1 + 2 * 3', static fn (array $vars): string => php_math_expected('1 + 2 * 3', $vars)],
    ['(1 + 2) * 3', static fn (array $vars): string => php_math_expected('(1 + 2) * 3', $vars)],
    ['2 ** 3 ** 2', static fn (array $vars): string => php_math_expected('2 ** 3 ** 2', $vars)],
    ['let radius = 3', static fn (array $vars): string => 'radius = ' . php_math_expected('3', $vars)],
    ['pi() * $radius ** 2', static fn (array $vars): string => php_math_expected('pi() * $radius ** 2', $vars)],
    ['x = 10 / 4', static fn (array $vars): string => 'x = ' . php_math_expected('10 / 4', $vars)],
    ['$x * 8', static fn (array $vars): string => php_math_expected('$x * 8', $vars)],
    ['max(1, $x, 3)', static fn (array $vars): string => php_math_expected('max(1, $x, 3)', $vars)],
    ['sqrt(81)', static fn (array $vars): string => php_math_expected('sqrt(81)', $vars)],
    [':vars', static fn (array $vars): string => 'vars: radius=' . jinx_math_format($vars['radius']) . ', x=' . jinx_math_format($vars['x'])],
    [':clear', 'vars: <cleared>'],
    [':vars', 'vars: <empty>'],
];

foreach ($cases as [$line, $expected]) {
    $expected = is_callable($expected) ? $expected($vars) : $expected;
    $actual = jinx_math_eval_line($line, $vars);
    if ($actual !== $expected) {
        throw new RuntimeException("Line {$line} expected {$expected}, got {$actual}");
    }
}

$input = implode("\n", [
    '2 + 2',
    'a = 5',
    '$a * 9',
    '$bad + 1',
    ':quit',
    '',
]);
$cmd = sprintf('php %s', escapeshellarg($root . '/examples/jinx-math-shell.php'));
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open($cmd, $descriptors, $pipes, $root);
if (!is_resource($process)) {
    throw new RuntimeException('Failed to launch math shell');
}
fwrite($pipes[0], $input);
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($process);
if ($code !== 0) {
    throw new RuntimeException("Math shell exited {$code}: {$stderr}");
}
foreach (['JINX math shell', '4', 'a = 5', '45', 'error: Undefined variable $bad', 'bye'] as $needle) {
    if (!str_contains($stdout, $needle)) {
        throw new RuntimeException('Shell output missing: ' . $needle . "\nOutput:\n" . $stdout);
    }
}

/** @return array<int,string> */
function run_checked(string $cmd, string $label): array
{
    $out = [];
    exec($cmd . ' 2>&1', $out, $code);
    if ($code !== 0) {
        throw new RuntimeException($label . " failed:\n" . implode("\n", $out));
    }
    return $out;
}

function compile_shell_line_native(string $root, string $tmp, string $name, array $vars, string $expr, string $expectedText): void
{
    $prefix = "<?php\n";
    foreach ($vars as $var => $value) {
        if (abs($value - round($value)) > 1e-12) {
            throw new RuntimeException('Native pipeline fixture only supports integer shell vars');
        }
        $prefix .= '$' . $var . ' = ' . (int)round($value) . ";\n";
    }
    $source = $prefix . 'return ' . $expr . ";\n";
    $proceduralSource = $prefix . 'echo ' . $expr . ", PHP_EOL;\n";
    $sourcePath = $tmp . '/' . $name . '.php';
    $proceduralPath = $tmp . '/' . $name . '.procedural.php';
    $jinxPath = $tmp . '/' . $name . '.jinx.json';
    $pasmPath = $tmp . '/' . $name . '.pasm';
    $cPath = $tmp . '/' . $name . '_native.c';
    file_put_contents($sourcePath, $source);
    file_put_contents($proceduralPath, $proceduralSource);

    run_checked(sprintf('php -l %s', escapeshellarg($sourcePath)), "compiler PHP lint for {$name}");
    run_checked(sprintf('php -l %s', escapeshellarg($proceduralPath)), "procedural PHP lint for {$name}");

    $proceduralOut = run_checked(sprintf('php %s', escapeshellarg($proceduralPath)), "procedural PHP run for {$name}");
    $proceduralText = trim(implode("\n", $proceduralOut));
    if ($proceduralText !== $expectedText) {
        throw new RuntimeException("Procedural PHP line {$name} returned {$proceduralText}, expected {$expectedText}");
    }

    $cmd = sprintf(
        'php %s %s --jinx-out %s --pasm-out %s',
        escapeshellarg($root . '/scripts/php-to-jinx.php'),
        escapeshellarg($sourcePath),
        escapeshellarg($jinxPath),
        escapeshellarg($pasmPath)
    );
    run_checked($cmd, "php-to-jinx for {$name}");

    $cmd = sprintf('php %s %s %s', escapeshellarg($root . '/scripts/jinx-native-attach.php'), escapeshellarg($sourcePath), escapeshellarg($cPath));
    run_checked($cmd, "native attachment generation for {$name}");

    $wslDistro = null;
    foreach (['Ubuntu', 'Ubuntu-24.04'] as $candidate) {
        exec('wsl -d ' . $candidate . ' -- sh -lc "command -v gcc" 2>NUL', $found, $foundCode);
        if ($foundCode === 0) {
            $wslDistro = $candidate;
            break;
        }
    }
    if ($wslDistro === null) {
        throw new RuntimeException('WSL gcc is required for this pipeline test');
    }
    $wslRoot = trim(shell_exec('wsl -d ' . $wslDistro . ' -- wslpath -a ' . escapeshellarg($root)) ?? '');
    $wslTmp = trim(shell_exec('wsl -d ' . $wslDistro . ' -- wslpath -a ' . escapeshellarg($tmp)) ?? '');
    $wslC = trim(shell_exec('wsl -d ' . $wslDistro . ' -- wslpath -a ' . escapeshellarg($cPath)) ?? '');
    $wslExe = $wslTmp . '/' . $name . '_native';
    $compile = sprintf(
        'wsl -d %s -- gcc -DJINX_PASM_NATIVE_STANDALONE -I%s -o %s %s',
        $wslDistro,
        escapeshellarg($wslRoot . '/include'),
        escapeshellarg($wslExe),
        escapeshellarg($wslC)
    );
    run_checked($compile, "WSL native compile for {$name}");
    exec('wsl -d ' . $wslDistro . ' -- ' . escapeshellarg($wslExe), $out, $code);
    $expectedExit = ((int)round((float)$expectedText)) & 0xff;
    if ($code !== $expectedExit) {
        throw new RuntimeException("Native shell line {$name} returned {$code}, expected {$expectedExit}");
    }
}

$pipelineTmp = sys_get_temp_dir() . '/jinx-math-shell-pipeline-' . bin2hex(random_bytes(4));
mkdir($pipelineTmp);
$pipeVars = [];
$pipeline = [
    ['line' => 'a = 6', 'expr' => '6'],
    ['line' => 'b = 7', 'expr' => '7'],
    ['line' => '$a * $b', 'expr' => '$a * $b'],
    ['line' => '($a + $b) * 3 - 4', 'expr' => '($a + $b) * 3 - 4'],
];
foreach ($pipeline as $index => $step) {
    $shellResult = jinx_math_eval_line($step['line'], $pipeVars);
    if ($shellResult === null) {
        throw new RuntimeException('Pipeline shell line returned null');
    }
    $numericResult = str_contains($shellResult, '=') ? trim(substr($shellResult, strpos($shellResult, '=') + 1)) : $shellResult;
    compile_shell_line_native($root, $pipelineTmp, 'line_' . $index, $pipeVars, $step['expr'], $numericResult);
}

echo "PASS: JINX dynamic math shell and per-line JINX/PASM/native build pipeline\n";
