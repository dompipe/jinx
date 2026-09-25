<?php

declare(strict_types=1);

use jinx\runtime\JinxPhpFamilyManifest;

require_once dirname(__DIR__) . '/runtime/jinx_php_family_manifest.php';

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$buildFirst = getenv('JINX_SKIP_BUILD') !== '1';

function fail_family_report(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function shell_output_family_report(string $cmd): string
{
    $lines = [];
    exec($cmd . ' 2>&1', $lines, $code);
    $out = implode(PHP_EOL, $lines);
    if ((int)$code !== 0) {
        fail_family_report("command failed ({$code}): {$cmd}\n{$out}");
    }
    return $out;
}

if ($buildFirst) {
    shell_output_family_report('cd ' . escapeshellarg($root) . ' && ./scripts/build-native-jinx.sh');
}

if (!is_file($jinx)) {
    fail_family_report('missing ./jinx; run ./scripts/build-native-jinx.sh first');
}

$output = shell_output_family_report('cd ' . escapeshellarg($root) . ' && ./jinx functions');
$names = [];
foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
    if (preg_match('/^\s*\d+\s+(.+)$/', $line, $m)) {
        $names[] = trim($m[1]);
    }
}

if ($names === []) {
    fail_family_report('./jinx functions returned no names');
}

$families = [];
$states = [];
foreach ($names as $name) {
    $spec = JinxPhpFamilyManifest::classify($name);
    $family = (string)$spec['family'];
    $state = (string)$spec['state'];

    $families[$family] ??= [
        'count' => 0,
        'state' => $state,
        'oracle_target' => $spec['oracle_target'] ?? '',
        'pasm_target' => $spec['pasm_target'] ?? '',
        'php_src' => implode(', ', array_map('strval', $spec['php_src'] ?? [])),
        'examples' => [],
    ];
    $families[$family]['count']++;
    if (count($families[$family]['examples']) < 6) {
        $families[$family]['examples'][] = $name;
    }

    $states[$state] = ($states[$state] ?? 0) + 1;
}

uasort($families, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
arsort($states);

printf("JINX PHP source-family report\n");
printf("Generated callable names: %d\n", count($names));
printf("Families: %d\n\n", count($families));

printf("By state:\n");
foreach ($states as $state => $count) {
    printf("- %-38s %5d\n", $state, $count);
}

printf("\nBy family:\n");
printf("%-46s %7s  %-36s  %s\n", 'family', 'count', 'state', 'oracle target');
printf("%'-120s\n", '');
foreach ($families as $family => $info) {
    printf("%-46s %7d  %-36s  %s\n", $family, $info['count'], $info['state'], $info['oracle_target']);
}

printf("\nFamily details:\n");
foreach ($families as $family => $info) {
    printf("\n[%s]\n", $family);
    printf("  count:        %d\n", $info['count']);
    printf("  state:        %s\n", $info['state']);
    printf("  php-src:      %s\n", $info['php_src']);
    printf("  oracle:       %s\n", $info['oracle_target']);
    printf("  pasm:         %s\n", $info['pasm_target']);
    printf("  examples:     %s\n", implode(', ', $info['examples']));
}
