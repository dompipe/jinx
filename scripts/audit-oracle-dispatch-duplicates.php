<?php

declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php scripts/audit-oracle-dispatch-duplicates.php build/oracle-asm/oracle_asm_index.json\n");
    exit(1);
}

$indexPath = $argv[1];
if (!is_file($indexPath)) {
    fwrite(STDERR, "Missing Oracle ASM index: {$indexPath}\n");
    exit(1);
}

$index = json_decode((string) file_get_contents($indexPath), true);
if (!is_array($index)) {
    fwrite(STDERR, "Invalid Oracle ASM index JSON: {$indexPath}\n");
    exit(1);
}

$baseDir = dirname($indexPath);
$seen = [];
$duplicates = [];
$total = 0;

foreach (($index['files'] ?? []) as $file) {
    if (!is_array($file)) {
        continue;
    }

    $outputFile = $file['output_file'] ?? null;
    if (!is_string($outputFile) || $outputFile === '') {
        continue;
    }

    $headerPath = $baseDir . '/' . $outputFile;
    if (!is_file($headerPath)) {
        continue;
    }

    $text = (string) file_get_contents($headerPath);
    preg_match_all(
        '/\/\*\s*Callable:\s*([^*]+?)\s*\*\/\s*static\s+inline\s+JinxValue\s+(jinx_ora_[A-Za-z0-9_]+)\s*\(/',
        $text,
        $matches,
        PREG_SET_ORDER
    );

    foreach ($matches as $match) {
        $total++;
        $name = trim($match[1]);
        $wrapper = trim($match[2]);
        if ($name === '' || $wrapper === '') {
            continue;
        }

        $entry = [
            'file' => $outputFile,
            'wrapper' => $wrapper,
        ];

        if (isset($seen[$name])) {
            if (!isset($duplicates[$name])) {
                $duplicates[$name] = [$seen[$name]];
            }
            $duplicates[$name][] = $entry;
        } else {
            $seen[$name] = $entry;
        }
    }
}

if ($duplicates !== []) {
    fwrite(STDERR, "FAIL: duplicate Oracle dispatch callable names found; refusing possible override by later wrapper\n");
    $shown = 0;
    foreach ($duplicates as $name => $entries) {
        fwrite(STDERR, "duplicate callable: {$name}\n");
        foreach ($entries as $entry) {
            fwrite(STDERR, "  - {$entry['wrapper']} in {$entry['file']}\n");
        }
        $shown++;
        if ($shown >= 25) {
            $remaining = count($duplicates) - $shown;
            if ($remaining > 0) {
                fwrite(STDERR, "  ... {$remaining} more duplicate callable name(s) omitted\n");
            }
            break;
        }
    }
    exit(1);
}

printf(
    "PASS: Oracle dispatch duplicate audit checked %d wrapper record(s); no callable can be overwritten by a later generated wrapper\n",
    $total
);
