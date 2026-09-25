<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jinx-native-' . bin2hex(random_bytes(4)) . '.json';
$cmd = sprintf('php %s %s', escapeshellarg($root . '/scripts/build-php-native-commands.php'), escapeshellarg($tmp));
exec($cmd, $output, $code);
if ($code !== 0) {
    throw new RuntimeException("build-php-native-commands failed:\n" . implode("\n", $output));
}

$catalog = json_decode(file_get_contents($tmp), true, 512, JSON_THROW_ON_ERROR);
if (($catalog['jinx'] ?? '') !== 'JINX-PHP-NATIVE-COMMANDS/0.1') {
    throw new RuntimeException('Unexpected native commands catalog marker');
}
if (($catalog['count'] ?? 0) < 500) {
    throw new RuntimeException('Native command catalog is unexpectedly small');
}

$byName = [];
foreach ($catalog['commands'] as $entry) {
    $byName[$entry['name']] = $entry;
}
foreach (['strlen', 'json_encode', 'array_map'] as $required) {
    if (!isset($byName[$required])) {
        throw new RuntimeException('Missing native PHP command: ' . $required);
    }
    if (($byName[$required]['commands'][array_key_last($byName[$required]['commands'])]['command'] ?? null) !== 'native-php-call') {
        throw new RuntimeException('Missing native call command for ' . $required);
    }
}
if (($byName['strlen']['commands'][0]['command'] ?? null) !== 'valuation-match') {
    throw new RuntimeException('strlen argument is not valuation-matched first');
}

echo "PASS: PHP native command catalog generated\n";
