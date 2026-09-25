<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$outDir = $root . '/build/jinx-chaos-runs';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

/** @param array<string,mixed> $expect */
function runJinxChaosFixture(string $name, string $source, array $expect): array
{
    global $root, $outDir;
    $sourcePath = $outDir . '/' . $name . '.php';
    $jinxPath = $outDir . '/' . $name . '.jinx.json';
    $reconstructPath = $outDir . '/' . $name . '.reconstructed.php';
    $pasmPath = $outDir . '/' . $name . '.pasm';
    file_put_contents($sourcePath, $source);

    $args = [
        'php',
        escapeshellarg($root . '/scripts/php-to-jinx.php'),
        escapeshellarg($sourcePath),
        '--jinx-out',
        escapeshellarg($jinxPath),
        '--reconstruct-out',
        escapeshellarg($reconstructPath),
    ];
    if (($expect['pasm'] ?? false) === true) {
        $args[] = '--pasm-out';
        $args[] = escapeshellarg($pasmPath);
    }
    exec(implode(' ', $args) . ' 2>&1', $output, $code);
    if ($code !== ($expect['exitCode'] ?? 0)) {
        throw new RuntimeException("{$name}: unexpected exit code {$code}\n" . implode("\n", $output));
    }
    if (!is_file($jinxPath)) {
        throw new RuntimeException("{$name}: missing JINX output");
    }
    $jinx = json_decode(file_get_contents($jinxPath), true, 512, JSON_THROW_ON_ERROR);
    if (($jinx['reconstruction']['sha256'] ?? null) !== hash('sha256', $source)) {
        throw new RuntimeException("{$name}: reconstruction hash mismatch");
    }
    if (file_get_contents($reconstructPath) !== $source) {
        throw new RuntimeException("{$name}: exact reconstruction mismatch");
    }
    foreach ($expect['tokens'] ?? [] as $tokenName) {
        if (!in_array($tokenName, array_column($jinx['tokens'], 'name'), true)) {
            throw new RuntimeException("{$name}: expected token {$tokenName}");
        }
    }
    foreach ($expect['statements'] ?? [] as $index => $kind) {
        if (($jinx['ast'][$index]['kind'] ?? null) !== $kind) {
            throw new RuntimeException("{$name}: statement {$index} expected {$kind}");
        }
    }
    foreach ($expect['actions'] ?? [] as $index => $command) {
        if (($jinx['lowering']['actions'][$index]['call'] ?? null) !== $command) {
            throw new RuntimeException("{$name}: lowering action {$index} expected {$command}");
        }
    }
    foreach ($expect['containsPasm'] ?? [] as $snippet) {
        if (!str_contains(file_get_contents($pasmPath), $snippet)) {
            throw new RuntimeException("{$name}: missing PASM snippet {$snippet}");
        }
    }
    return $jinx;
}

$fixtures = [
    'spaced_arithmetic' => [
        'source' => <<<'PHP'
<?php
/* deliberately odd layout */
$a=1;
      $b    =     2;
$c = ( $a + $b )
*
3;
return   $c;
PHP,
        'expect' => [
            'pasm' => true,
            'tokens' => ['T_COMMENT', 'T_VARIABLE', 'T_LNUMBER', 'T_RETURN'],
            'statements' => ['assign', 'assign', 'assign', 'return'],
            'actions' => ['add', 'mul', 'add'],
            'containsPasm' => ['; operator + -> PASM::add()', '; operator * -> PASM::mul()', '; valuation-match 9 -> ecx (return-value)'],
        ],
    ],
    'string_confetti' => [
        'source' => <<<'PHP'
<?php
$who="jinx"; // lowercase on purpose
$bang = "!";
echo
    "hi $who" . $bang;
PHP,
        'expect' => [
            'pasm' => true,
            'tokens' => ['T_ENCAPSED_AND_WHITESPACE', 'T_VARIABLE', 'T_ECHO'],
            'statements' => ['assign', 'assign', 'echo'],
            'actions' => ['string-concat', 'string-output'],
            'containsPasm' => ['load_str "hi jinx"', 'load_str "!"', '; result buffer = "hi jinx!"'],
        ],
    ],
    'native_call_shape_only' => [
        'source' => <<<'PHP'
<?php
$x = strlen(
 "abc"
);
json_encode($x);
PHP,
        'expect' => [
            'tokens' => ['T_STRING', 'T_VARIABLE', 'T_CONSTANT_ENCAPSED_STRING'],
            'statements' => ['assign', 'expr'],
        ],
    ],
    'function_shape_only' => [
        'source' => <<<'PHP'
<?php
function odd_sum( $left , $right ){
    return ($left+$right) * 2;
}
PHP,
        'expect' => [
            'tokens' => ['T_FUNCTION', 'T_STRING', 'T_RETURN'],
            'statements' => ['function'],
        ],
    ],
    'class_construct_shape' => [
        'source' => <<<'PHP'
<?php
class Later {
    private $thing = "x";
    public function notYet() {
        return $this->thing;
    }
}
$made = new Later();
$made->thing = "changed";
$made->notYet();
PHP,
        'expect' => [
            'tokens' => ['T_CLASS'],
            'statements' => ['class'],
        ],
    ],
];

$results = [];
foreach ($fixtures as $name => $fixture) {
    $results[$name] = runJinxChaosFixture($name, $fixture['source'], $fixture['expect']);
}

if (($results['native_call_shape_only']['ast'][0]['expr']['kind'] ?? null) !== 'call') {
    throw new RuntimeException('native_call_shape_only: assignment did not retain call expression');
}
if (($results['native_call_shape_only']['ast'][1]['expr']['name'] ?? null) !== 'json_encode') {
    throw new RuntimeException('native_call_shape_only: native call name was not retained');
}
if (($results['function_shape_only']['ast'][0]['body'][0]['expr']['op'] ?? null) !== '*') {
    throw new RuntimeException('function_shape_only: nested function body expression was not retained');
}
if (($results['class_construct_shape']['lowering']['classConstructs'][0]['commands'][4]['command'] ?? null) !== 'define-field-slot') {
    throw new RuntimeException('class_construct_shape: expected field slot construct');
}
if (($results['class_construct_shape']['lowering']['classConstructs'][0]['commands'][5]['command'] ?? null) !== 'define-method-label') {
    throw new RuntimeException('class_construct_shape: expected method label construct');
}
if (($results['class_construct_shape']['lowering']['objectInstances']['Later'][0]['made']['__vptr'] ?? null) !== 'vtable.Later' || ($results['class_construct_shape']['lowering']['objectInstances']['Later'][0]['made']['thing'] ?? null) !== 'changed') {
    throw new RuntimeException('class_construct_shape: expected created object property map');
}
if (($results['class_construct_shape']['lowering']['methodDispatches'][0]['pasmChain']['commands'][0]['command'] ?? null) !== 'valuation-match-this') {
    throw new RuntimeException('class_construct_shape: expected hidden-this method dispatch');
}

echo "PASS: JINX chaos fixtures generated in {$outDir}\n";
