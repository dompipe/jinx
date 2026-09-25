<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . '/jinx-php-src-stub-fixture-' . getmypid();
@mkdir($root . '/ext/standard', 0777, true);
@mkdir($root . '/ext/spl', 0777, true);
file_put_contents($root . '/ext/standard/basic_functions.stub.php', <<<'PHPSTUB'
<?php
function strlen(string $string): int {}
function array_push(array &$array, mixed ...$values): int {}
PHPSTUB);
file_put_contents($root . '/ext/spl/spl_directory.stub.php', <<<'PHPSTUB'
<?php
class DirectoryIterator {
    public function getFilename(): string {}
}
PHPSTUB);

$out = $root . '/manifest.json';
$cmd = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/import-php-src-stubs.php') . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($out) . ' 2>&1';
exec($cmd, $lines, $code);
if ($code !== 0) {
    throw new RuntimeException("import failed:\n" . implode("\n", $lines));
}
$json = json_decode(file_get_contents($out) ?: '', true, 512, JSON_THROW_ON_ERROR);
$byName = [];
foreach ($json['functions'] as $fn) {
    $byName[$fn['name']] = $fn;
}
foreach (['strlen', 'array_push', 'DirectoryIterator::getFilename'] as $name) {
    if (!isset($byName[$name])) {
        throw new RuntimeException("missing imported callable {$name}");
    }
}
if (($byName['array_push']['parameters'][0]['by_ref'] ?? false) !== true) {
    throw new RuntimeException('array_push first parameter must be by_ref');
}
if (($byName['array_push']['parameters'][1]['variadic'] ?? false) !== true) {
    throw new RuntimeException('array_push values parameter must be variadic');
}
if (($byName['DirectoryIterator::getFilename']['kind'] ?? '') !== 'method') {
    throw new RuntimeException('DirectoryIterator::getFilename must import as method');
}

$verify = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/verify-php-function-manifest.php') . ' ' . escapeshellarg($out) . ' 2>&1';
exec($verify, $verifyLines, $verifyCode);
if ($verifyCode !== 0) {
    throw new RuntimeException("generated manifest verification failed:\n" . implode("\n", $verifyLines));
}

echo "PASS: php-src stub importer captured functions, by-ref/variadic params, and methods\n";
