<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$build = $root . '/scripts/build-oracle-dispatch-zend-array-smoke.sh';
$binary = $root . '/build/native/jinx-oracle-dispatch-zend-array-smoke';

$out = [];
$code = 0;
exec('sh ' . escapeshellarg($build) . ' 2>&1', $out, $code);

if ($code !== 0) {
    fwrite(STDERR, "FAIL: Zend-array native core build failed\n" . implode(PHP_EOL, $out) . PHP_EOL);
    exit(1);
}

$out = [];
exec(escapeshellarg($binary) . ' 2>&1', $out, $code);
$text = implode(PHP_EOL, $out);

if ($code !== 0 || !str_contains($text, 'PASS: Oracle generated dispatch Zend-array native core passed')) {
    fwrite(STDERR, "FAIL: Zend-array native core smoke failed\n{$text}\n");
    exit(1);
}

$pairs = static function (array $value): string {
    $out = [];
    foreach ($value as $key => $item) {
        $out[] = $key . ':' . $item;
    }
    return implode(',', $out);
};

$mustReject = static function (callable $callable, string $label): void {
    try {
        $callable();
    } catch (Throwable) {
        return;
    }

    fwrite(STDERR, "FAIL: PHP did not reject {$label}\n");
    exit(1);
};

$mustReject(static fn () => count_chars('abc', 5), 'count_chars invalid mode');
$mustReject(static fn () => range(1, 5, -1), 'range negative increasing step');
$mustReject(static fn () => range(1, 5, 9), 'range step larger than span');
$mustReject(static fn () => range(1, 5, 0), 'range zero step');
$mustReject(static fn () => array_fill(2, -1, 9), 'array_fill negative count');
$mustReject(static fn () => array_combine([1, 2], [3]), 'array_combine mismatched counts');
$mustReject(static fn () => str_getcsv('a,b', '::', '"', '\\'), 'str_getcsv multi-byte separator');

$base = [10, 20, 'name' => 30, 'keep' => 40];
$mutation = [10, 'x' => 20, 2 => 30];
$pushCount = array_push($mutation, 40);
$popValue = array_pop($mutation);
array_push($mutation, 50);
$shiftValue = array_shift($mutation);
$unshiftCount = array_unshift($mutation, 5, 6);

$splice = [10, 'keep' => 20, 2 => 30, 'tail' => 40, 5 => 50];
$spliced = array_splice($splice, 2, 2, [70, 80]);

$mergeRecursive = array_merge_recursive(
    ['color' => ['favorite' => 'red'], 5],
    [10, 'color' => ['favorite' => 'green', 'blue']]
);
$replaceRecursive = array_replace_recursive(
    ['citrus' => ['orange', 'lemon'], 'pome' => ['apple']],
    ['citrus' => ['grapefruit']],
    ['citrus' => ['kumquat', 'citron'], 'pome' => ['loquat']]
);

$csv = str_getcsv('a,b,c', ',', '"', '\\');
$csvQuoted = str_getcsv(' "a,b",c', ',', '"', '\\');
$csvEscaped = str_getcsv('"a""b","c\\"d"', ',', '"', '\\');
$csvTrailing = str_getcsv("a,\r\n", ',', '"', '\\');
$csvEmpty = str_getcsv('', ',', '"', '\\');

$expectedParity = 'PARITY:'
    . 'recursive=' . count([[1, 2], 3], COUNT_RECURSIVE)
    . ';keys_loose=' . implode(',', array_keys($base, '20', false))
    . ';keys_strict=' . implode(',', array_keys($base, '20', true))
    . ';sum=' . array_sum($base)
    . ';product=' . array_product($base)
    . ';words=' . str_word_count('Hello, world!')
    . ';words1=' . implode(',', str_word_count('Hello, world!', 1))
    . ';words2=' . $pairs(str_word_count('Hello, world!', 2))
    . ';words_digits=' . str_word_count('abc123', 0, '0..9')
    . ';implode=' . implode(',', $base)
    . ';vsprintf=' . vsprintf('There are %u million bicycles in %s.', [7, 'Amsterdam'])
    . ';range=' . implode(',', range(1, 5))
    . ';range_neg=' . implode(',', range(5, 1, -2))
    . ';fill=' . $pairs(array_fill(2, 3, 9))
    . ';fill_zero=' . count(array_fill(2, 0, 9))
    . ';combine=' . $pairs(array_combine([2, 'x'], [70, 80]))
    . ';fill_keys_num=' . $pairs(array_fill_keys(['2', '02', '+2', '-2'], 9))
    . ';combine_num=' . $pairs(array_combine(['2', '02'], [70, 80]))
    . ';count_values=' . $pairs(array_count_values([2, 2, 'x', 'x', 'x']))
    . ';count_values_num=' . $pairs(array_count_values([2, '2', '02']))
    . ';chunk0=' . implode(',', array_chunk($base, 2)[0])
    . ';pad=' . implode(',', array_pad($base, 6, 0))
    . ';unique=' . $pairs(array_unique([4, '4', '3', 4, 3, '3']))
    . ';diff=' . $pairs(array_diff($base, [20, 40]))
    . ';intersect=' . $pairs(array_intersect($base, [20, 40]))
    . ';intersect3=' . $pairs(array_intersect($base, [20, 40], [40, 50]))
    . ';explode=' . implode(',', explode(',', 'a,b,c'))
    . ';split=' . implode(',', str_split('abcdef', 2))
    . ';column=' . $pairs(array_column([
        ['id' => 1, 'name' => 'Ada'],
        ['id' => 2, 'name' => 'Grace'],
    ], 'name', 'id'))
    . ';column_num=' . $pairs(array_column([
        ['id' => '1', 'name' => 'Ada'],
        ['id' => '02', 'name' => 'Grace'],
    ], 'name', 'id'))
    . ';in_loose=' . (in_array('20', $base, false) ? '1' : '0')
    . ';in_strict=' . (in_array('20', $base, true) ? '1' : '0')
    . ';search_loose=' . (($search = array_search('30', $base, false)) === false ? 'false' : $search)
    . ';search_strict=' . (array_search('30', $base, true) === false ? 'false' : 'unexpected')
    . ';filter=' . $pairs(array_filter([0, 1, '0', 'x', false]))
    . ';push=' . $pushCount
    . ';pop=' . $popValue
    . ';shift=' . $shiftValue
    . ';unshift=' . $unshiftCount
    . ';mutation=' . $pairs($mutation)
    . ';splice=' . $pairs($splice)
    . ';spliced=' . $pairs($spliced)
    . ';merge_rec='
        . implode(',', $mergeRecursive['color']['favorite']) . ','
        . $mergeRecursive['color'][0] . ','
        . $mergeRecursive[0] . ','
        . $mergeRecursive[1]
    . ';replace_rec='
        . implode(',', $replaceRecursive['citrus']) . ','
        . $replaceRecursive['pome'][0]
    . ';csv=' . implode('|', $csv)
    . ';csvq=' . implode('|', $csvQuoted)
    . ';csvesc=' . implode('|', $csvEscaped)
    . ';csvtrail=' . implode('|', $csvTrailing)
    . ';csvempty=' . (($csvEmpty[0] ?? 'not-null') === null ? 'null' : 'not-null');

if (!str_contains($text, $expectedParity)) {
    fwrite(STDERR, "FAIL: PHP-vs-JINX Zend-array parity mismatch\nPHP: {$expectedParity}\nJINX:\n{$text}\n");
    exit(1);
}

echo 'PASS: native Oracle ASM Zend-array core matches PHP for covered carried-array semantics' . PHP_EOL;
