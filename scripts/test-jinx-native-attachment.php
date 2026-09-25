<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jinx-native-attach-' . bin2hex(random_bytes(4));
mkdir($tmp);
$source = $tmp . '/math.php';
$c = $tmp . '/math_native.c';
file_put_contents($source, <<<'PHP'
<?php
$a = 6;
$b = 7;
return $a * $b;
PHP);

$cmd = sprintf('php %s %s %s', escapeshellarg($root . '/scripts/jinx-native-attach.php'), escapeshellarg($source), escapeshellarg($c));
exec($cmd . ' 2>&1', $output, $code);
if ($code !== 0) {
    throw new RuntimeException("native attachment generation failed:\n" . implode("\n", $output));
}
$generated = file_get_contents($c);
foreach (['JinxPasmCommand', 'JINX_PASM_OP_SET_I64', 'JINX_PASM_OP_ADD', 'JINX_PASM_OP_YIELD', 'JINX_PASM_OP_END', 'jinx_pasm_run_struct_chain'] as $needle) {
    if (!str_contains($generated, $needle)) {
        throw new RuntimeException('Missing generated native attachment fragment: ' . $needle);
    }
}

$compiler = null;
foreach (['gcc', 'clang', 'cc'] as $candidate) {
    exec('where ' . escapeshellarg($candidate) . ' 2>NUL', $found, $foundCode);
    if ($foundCode === 0) {
        $compiler = $candidate;
        break;
    }
}
if ($compiler !== null) {
    $exe = $tmp . '/math_native.exe';
    $compile = sprintf('%s -DJINX_PASM_NATIVE_STANDALONE -I%s -o %s %s', escapeshellarg($compiler), escapeshellarg($root . '/include'), escapeshellarg($exe), escapeshellarg($c));
    exec($compile . ' 2>&1', $compileOut, $compileCode);
    if ($compileCode !== 0) {
        throw new RuntimeException("native attachment C compile failed:\n" . implode("\n", $compileOut));
    }
    exec(escapeshellarg($exe), $runOut, $runCode);
    if ($runCode !== 42) {
        throw new RuntimeException('native attachment executable returned ' . $runCode . ', expected 42');
    }
    echo "PASS: JINX native struct attachment generated, compiled, and returned 42\n";
} elseif (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
    $wslDistro = null;
    foreach (['Ubuntu', 'Ubuntu-24.04'] as $candidate) {
        exec('wsl -d ' . $candidate . ' -- sh -lc "command -v gcc" 2>NUL', $wslFound, $wslCode);
        if ($wslCode === 0) {
            $wslDistro = $candidate;
            break;
        }
    }
    if ($wslDistro === null) {
        echo "PASS: JINX native struct attachment generated (C compiler not found; compile/run skipped)\n";
        exit(0);
    }
    $wslRoot = trim(shell_exec('wsl -d ' . $wslDistro . ' -- wslpath -a ' . escapeshellarg($root)) ?? '');
    $wslTmp = trim(shell_exec('wsl -d ' . $wslDistro . ' -- wslpath -a ' . escapeshellarg($tmp)) ?? '');
    $wslC = trim(shell_exec('wsl -d ' . $wslDistro . ' -- wslpath -a ' . escapeshellarg($c)) ?? '');
    $wslExe = $wslTmp . '/math_native';
    $compile = sprintf(
        'wsl -d %s -- gcc -DJINX_PASM_NATIVE_STANDALONE -I%s -o %s %s',
        $wslDistro,
        escapeshellarg($wslRoot . '/include'),
        escapeshellarg($wslExe),
        escapeshellarg($wslC)
    );
    exec($compile . ' 2>&1', $compileOut, $compileCode);
    if ($compileCode !== 0) {
        throw new RuntimeException("WSL native attachment C compile failed:\n" . implode("\n", $compileOut));
    }
    exec('wsl -d ' . $wslDistro . ' -- ' . escapeshellarg($wslExe), $runOut, $runCode);
    if ($runCode !== 42) {
        throw new RuntimeException('WSL native attachment executable returned ' . $runCode . ', expected 42');
    }
    echo "PASS: JINX native struct attachment generated, compiled in WSL {$wslDistro}, and returned 42\n";
} else {
    echo "PASS: JINX native struct attachment generated (C compiler not found; compile/run skipped)\n";
}
