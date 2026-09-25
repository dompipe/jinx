<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = $root . '/runtime/jinx_php_manual_manifest.h';

if (!is_file($manifest)) {
    fwrite(STDERR, "FAIL: missing runtime/jinx_php_manual_manifest.h\n");
    exit(1);
}

$text = file_get_contents($manifest);
if ($text === false) {
    fwrite(STDERR, "FAIL: could not read runtime/jinx_php_manual_manifest.h\n");
    exit(1);
}

preg_match_all('/\{\s*"([^"]+)",\s*"([^"]+)",\s*"([^"]*)",\s*"([^"]*)",\s*"([^"]*)",\s*(JINX_PHP_MANUAL_[A-Z_]+),\s*"([^"]*)"\s*\}/s', $text, $matches, PREG_SET_ORDER);

if ($matches === []) {
    fwrite(STDERR, "FAIL: no manual handler specs found\n");
    exit(1);
}

$counts = [
    'JINX_PHP_MANUAL_EXACT' => 0,
    'JINX_PHP_MANUAL_PARTIAL' => 0,
    'JINX_PHP_MANUAL_PLACEHOLDER' => 0,
    'JINX_PHP_MANUAL_UNSAFE_NATIVE' => 0,
    'JINX_PHP_MANUAL_PHP_FALLBACK' => 0,
    'JINX_PHP_MANUAL_SANDBOX_BLOCKED' => 0,
];

$resolvedStates = [
    'JINX_PHP_MANUAL_EXACT' => true,
    'JINX_PHP_MANUAL_PHP_FALLBACK' => true,
    'JINX_PHP_MANUAL_SANDBOX_BLOCKED' => true,
];

$notResolved = [];

foreach ($matches as $m) {
    [$all, $pattern, $manual, $prototype, $returnFamily, $handler, $state, $notes] = $m;
    $counts[$state] = ($counts[$state] ?? 0) + 1;

    if (!isset($resolvedStates[$state])) {
        $notResolved[] = [$pattern, $state, $prototype, $notes];
    }
}

printf("Native PHP manual implementation status\n");
printf("Specs: %d\n", count($matches));
printf("Exact native: %d\n", $counts['JINX_PHP_MANUAL_EXACT']);
printf("PHP fallback: %d\n", $counts['JINX_PHP_MANUAL_PHP_FALLBACK']);
printf("Sandbox-blocked: %d\n", $counts['JINX_PHP_MANUAL_SANDBOX_BLOCKED']);
printf("Partial: %d\n", $counts['JINX_PHP_MANUAL_PARTIAL']);
printf("Placeholder: %d\n", $counts['JINX_PHP_MANUAL_PLACEHOLDER']);
printf("Unsafe-native: %d\n", $counts['JINX_PHP_MANUAL_UNSAFE_NATIVE']);
printf("\n");

if ($notResolved !== []) {
    fwrite(STDERR, "FAIL: native PHP mirror still has unresolved manual states:\n");
    foreach ($notResolved as [$pattern, $state, $prototype, $notes]) {
        $label = strtolower(str_replace('_', '-', str_replace('JINX_PHP_MANUAL_', '', $state)));
        fwrite(STDERR, "- {$pattern}: {$label}; {$prototype}; {$notes}\n");
    }
    exit(1);
}

printf("PASS: every manual manifest entry is resolved as exact-native, PHP-fallback, or sandbox-blocked.\n");
