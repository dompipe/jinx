<?php
declare(strict_types=1);

$out = sys_get_temp_dir() . '/jinx-runtime-reflection-manifest-' . getmypid() . '.json';
$cmd = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/import-php-runtime-reflection.php') . ' ' . escapeshellarg($out) . ' 2>&1';
exec($cmd, $lines, $code);
if ($code !== 0) {
    throw new RuntimeException("runtime reflection import failed:\n" . implode("\n", $lines));
}
$json = json_decode(file_get_contents($out) ?: '', true, 512, JSON_THROW_ON_ERROR);
$byName = [];
foreach ($json['functions'] as $fn) {
    $byName[strtolower($fn['name'])] = $fn;
}
foreach (['strlen', 'array_push'] as $name) {
    if (!isset($byName[$name])) {
        throw new RuntimeException("missing reflected builtin {$name}");
    }
}
if (($byName['array_push']['parameters'][0]['by_ref'] ?? false) !== true) {
    throw new RuntimeException('array_push first parameter must be reflected as by-ref');
}
$hasMethod = false;
foreach ($byName as $lower => $fn) {
    if (($fn['kind'] ?? '') === 'method' && str_contains($lower, '::')) {
        $hasMethod = true;
        break;
    }
}
if (!$hasMethod) {
    throw new RuntimeException('expected at least one internal method from Reflection');
}
$verify = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/verify-php-function-manifest.php') . ' ' . escapeshellarg($out) . ' 2>&1';
exec($verify, $verifyLines, $verifyCode);
if ($verifyCode !== 0) {
    throw new RuntimeException("generated runtime manifest verification failed:\n" . implode("\n", $verifyLines));
}

echo "PASS: runtime Reflection importer captured broad internal callable inventory\n";
