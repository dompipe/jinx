<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jinx-' . bin2hex(random_bytes(4));
mkdir($tmp);

$source = <<<'PHP'
<?php
$left = 7;
$right = 5;
$scale = 2;
return ($left + $right) * $scale;
PHP;

$sourcePath = $tmp . '/sample.php';
$jinxPath = $tmp . '/sample.jinx.json';
$pasmPath = $tmp . '/sample.pasm';
$reconstructPath = $tmp . '/sample.reconstructed.php';
file_put_contents($sourcePath, $source);

$cmd = sprintf(
    'php %s %s --jinx-out %s --pasm-out %s --reconstruct-out %s',
    escapeshellarg($root . '/scripts/php-to-jinx.php'),
    escapeshellarg($sourcePath),
    escapeshellarg($jinxPath),
    escapeshellarg($pasmPath),
    escapeshellarg($reconstructPath)
);
exec($cmd, $output, $code);
if ($code !== 0) {
    throw new RuntimeException("php-to-jinx failed:\n" . implode("\n", $output));
}

$jinx = json_decode(file_get_contents($jinxPath), true, 512, JSON_THROW_ON_ERROR);
if (($jinx['jinx'] ?? '') !== 'JINX-PHP-PASM/0.1') {
    throw new RuntimeException('Unexpected JINX format marker');
}
if (hash('sha256', $source) !== ($jinx['reconstruction']['sha256'] ?? null)) {
    throw new RuntimeException('Reconstruction hash mismatch');
}
if (file_get_contents($reconstructPath) !== $source) {
    throw new RuntimeException('Exact reconstruction output mismatch');
}
$actions = $jinx['lowering']['actions'] ?? [];
if (($actions[0]['call'] ?? null) !== 'add' || ($actions[1]['call'] ?? null) !== 'mul') {
    throw new RuntimeException('Operators were not split into the expected PASM actions');
}
if (($actions[0]['matches'][0]['register'] ?? null) !== 'ecx' || ($actions[0]['matches'][0]['value'] ?? null) !== 7) {
    throw new RuntimeException('Left numeric value was not valuation-matched into ecx');
}
if (($actions[0]['matches'][1]['register'] ?? null) !== 'ah' || ($actions[0]['matches'][1]['value'] ?? null) !== 5) {
    throw new RuntimeException('Right numeric value was not valuation-matched into ah');
}
if (($actions[1]['operator'] ?? null) !== '*' || ($actions[1]['commands'][0]['command'] ?? null) !== 'set' || ($actions[1]['commands'][0]['register'] ?? null) !== 'ecx' || ($actions[1]['commands'][1]['command'] ?? null) !== 'set' || ($actions[1]['commands'][1]['register'] ?? null) !== 'ah' || ($actions[1]['commands'][2]['command'] ?? null) !== 'mul') {
    throw new RuntimeException('Multiply branch does not carry ordered compiler commands');
}
if (($actions[1]['pasmChain']['terminator'] ?? null) !== 'end' || ($actions[1]['pasmChain']['length'] ?? null) !== 5 || ($actions[1]['pasmChain']['commands'][3]['command'] ?? null) !== 'yield-value' || ($actions[1]['pasmChain']['commands'][4]['command'] ?? null) !== 'end') {
    throw new RuntimeException('Multiply branch does not carry a PASM chain ending in end()');
}
$pasm = file_get_contents($pasmPath);
foreach (['; operator + -> PASM::add()', '; valuation-match 7 -> ecx (left-operand)', 'set ecx 7', '; valuation-match 5 -> ah (right-operand)', 'set ah 5', 'add', '; operator * -> PASM::mul()', 'set ecx 12', 'set ah 2', 'mul', 'end'] as $expected) {
    if (!str_contains($pasm, $expected)) {
        throw new RuntimeException('Missing PASM instruction: ' . $expected);
    }
}

$stringSource = <<<'PHP'
<?php
$first = "jin";
$second = "x";
echo $first . $second . "!";
PHP;

$stringSourcePath = $tmp . '/string.php';
$stringJinxPath = $tmp . '/string.jinx.json';
$stringPasmPath = $tmp . '/string.pasm';
file_put_contents($stringSourcePath, $stringSource);
$cmd = sprintf(
    'php %s %s --jinx-out %s --pasm-out %s',
    escapeshellarg($root . '/scripts/php-to-jinx.php'),
    escapeshellarg($stringSourcePath),
    escapeshellarg($stringJinxPath),
    escapeshellarg($stringPasmPath)
);
exec($cmd, $output, $code);
if ($code !== 0) {
    throw new RuntimeException("string php-to-jinx failed:\n" . implode("\n", $output));
}
$stringJinx = json_decode(file_get_contents($stringJinxPath), true, 512, JSON_THROW_ON_ERROR);
$stringActions = $stringJinx['lowering']['actions'] ?? [];
if (($stringActions[0]['call'] ?? null) !== 'string-concat' || ($stringActions[1]['call'] ?? null) !== 'string-concat') {
    throw new RuntimeException('String operators were not split into PASM string actions');
}
if (($stringActions[0]['matches'][0]['register'] ?? null) !== 'string' || ($stringActions[0]['matches'][0]['value'] ?? null) !== 'jin') {
    throw new RuntimeException('String left value was not valuation-matched into the PASM string register');
}
if (($stringActions[0]['commands'][0]['command'] ?? null) !== 'clbuf' || ($stringActions[0]['commands'][1]['command'] ?? null) !== 'load_str' || ($stringActions[0]['commands'][2]['command'] ?? null) !== 'appbuf') {
    throw new RuntimeException('String branch does not carry ordered compiler commands');
}
if (($stringActions[0]['pasmChain']['terminator'] ?? null) !== 'end' || ($stringActions[0]['pasmChain']['commands'][5]['command'] ?? null) !== 'yield-value' || ($stringActions[0]['pasmChain']['commands'][6]['command'] ?? null) !== 'end') {
    throw new RuntimeException('String branch does not carry a PASM chain ending in end()');
}
$stringPasm = file_get_contents($stringPasmPath);
foreach (['; operator . -> PASM::clbuf(), PASM::load_str(), PASM::appbuf()', '; valuation-match "jin" -> string (concat-left)', 'load_str "jin"', 'load_str "x"', 'load_str "jinx"', 'load_str "!"', '; result buffer = "jinx!"', 'end'] as $expected) {
    if (!str_contains($stringPasm, $expected)) {
        throw new RuntimeException('Missing string PASM instruction: ' . $expected);
    }
}

$interpolatedSource = <<<'PHP'
<?php
$name = "JINX";
$count = 2;
echo "Hello $name $count";
PHP;

$interpolatedSourcePath = $tmp . '/interpolated.php';
$interpolatedJinxPath = $tmp . '/interpolated.jinx.json';
$interpolatedPasmPath = $tmp . '/interpolated.pasm';
file_put_contents($interpolatedSourcePath, $interpolatedSource);
$cmd = sprintf(
    'php %s %s --jinx-out %s --pasm-out %s',
    escapeshellarg($root . '/scripts/php-to-jinx.php'),
    escapeshellarg($interpolatedSourcePath),
    escapeshellarg($interpolatedJinxPath),
    escapeshellarg($interpolatedPasmPath)
);
exec($cmd, $output, $code);
if ($code !== 0) {
    throw new RuntimeException("interpolated php-to-jinx failed:\n" . implode("\n", $output));
}
$interpolatedJinx = json_decode(file_get_contents($interpolatedJinxPath), true, 512, JSON_THROW_ON_ERROR);
$echoExpr = $interpolatedJinx['ast'][2]['expr'] ?? [];
if (($echoExpr['kind'] ?? null) !== 'interpolated-string') {
    throw new RuntimeException('Double-quoted variables were not kept as interpolated-string parts');
}
$interpolatedPasm = file_get_contents($interpolatedPasmPath);
foreach (['load_str "Hello JINX 2"', 'appbuf', '; yield buffer as string via PASM::$buffer after output appbuf', 'end'] as $expected) {
    if (!str_contains($interpolatedPasm, $expected)) {
        throw new RuntimeException('Missing interpolated string PASM instruction: ' . $expected);
    }
}

$classSource = <<<'PHP'
<?php
class Hold {
    public $value = 3;
    public function getValue() {
        return $this->value;
    }
}
$box = new Hold();
$box->value = 9;
echo $box->getValue();
PHP;

$classSourcePath = $tmp . '/class.php';
$classJinxPath = $tmp . '/class.jinx.json';
file_put_contents($classSourcePath, $classSource);
exec(sprintf('php -l %s', escapeshellarg($classSourcePath)) . ' 2>&1', $output, $code);
if ($code !== 0) {
    throw new RuntimeException("class php lint failed:\n" . implode("\n", $output));
}
$output = [];
exec(sprintf('php %s', escapeshellarg($classSourcePath)) . ' 2>&1', $output, $code);
if ($code !== 0 || trim(implode("\n", $output)) !== '9') {
    throw new RuntimeException("class procedural PHP run failed or returned wrong value:\n" . implode("\n", $output));
}
$cmd = sprintf(
    'php %s %s --jinx-out %s',
    escapeshellarg($root . '/scripts/php-to-jinx.php'),
    escapeshellarg($classSourcePath),
    escapeshellarg($classJinxPath)
);
exec($cmd . ' 2>&1', $output, $code);
if ($code !== 0) {
    throw new RuntimeException("class php-to-jinx failed:\n" . implode("\n", $output));
}
$classJinx = json_decode(file_get_contents($classJinxPath), true, 512, JSON_THROW_ON_ERROR);
$classCommands = $classJinx['lowering']['classConstructs'][0]['commands'] ?? [];
if (($classJinx['ast'][0]['kind'] ?? null) !== 'class') {
    throw new RuntimeException('Class was not retained as a nested JINX class node');
}
if (($classCommands[0]['command'] ?? null) !== 'define-class') {
    throw new RuntimeException('Class did not emit a define-class construct command');
}
if (($classCommands[1]['command'] ?? null) !== 'define-vtable' || ($classCommands[3]['command'] ?? null) !== 'define-hidden-vptr-slot') {
    throw new RuntimeException('Class did not emit vtable and hidden vptr slot commands');
}
if (($classCommands[4]['command'] ?? null) !== 'define-field-slot' || ($classCommands[4]['field'] ?? null) !== 'value' || ($classCommands[4]['offset'] ?? null) !== 1) {
    throw new RuntimeException('Class property did not emit a field slot command');
}
if (($classCommands[5]['command'] ?? null) !== 'define-method-label' || ($classCommands[6]['command'] ?? null) !== 'define-vtable-entry') {
    throw new RuntimeException('Class method did not emit method label and vtable commands');
}
$objectInstances = $classJinx['lowering']['objectInstances'] ?? [];
if (($objectInstances['Hold'][0]['box']['__vptr'] ?? null) !== 'vtable.Hold' || ($objectInstances['Hold'][0]['box']['value'] ?? null) !== 9) {
    throw new RuntimeException('Created object did not materialize with __vptr and updated value');
}
if (($classJinx['lowering']['objectConstructions'][0]['pasmChain']['commands'][1]['command'] ?? null) !== 'set-vptr') {
    throw new RuntimeException('Object construction did not emit set-vptr chain command');
}
$methodBodyCommands = $classJinx['lowering']['methodBodies'][0]['pasmChain']['commands'] ?? [];
if (($methodBodyCommands[0]['command'] ?? null) !== 'begin-method' || ($methodBodyCommands[1]['command'] ?? null) !== 'bind-hidden-this') {
    throw new RuntimeException('Method body did not begin with method entry and hidden-this binding');
}
if (($methodBodyCommands[2]['command'] ?? null) !== 'read-property-slot' || ($methodBodyCommands[2]['object'] ?? null) !== 'this' || ($methodBodyCommands[2]['property'] ?? null) !== 'value' || ($methodBodyCommands[2]['slot'] ?? null) !== 1) {
    throw new RuntimeException('Method body did not lower $this->value to a property-slot read');
}
if (($methodBodyCommands[3]['command'] ?? null) !== 'return-value' || ($methodBodyCommands[4]['command'] ?? null) !== 'yield-value' || ($methodBodyCommands[5]['command'] ?? null) !== 'end') {
    throw new RuntimeException('Method body did not yield and end as a PASM function chain');
}
if (($classJinx['lowering']['methodDispatches'][0]['pasmChain']['commands'][0]['command'] ?? null) !== 'valuation-match-this' || ($classJinx['lowering']['methodDispatches'][0]['pasmChain']['commands'][1]['command'] ?? null) !== 'dispatch-method') {
    throw new RuntimeException('Method call did not emit hidden-this dispatch chain');
}

echo "PASS: PHP to JINX exact reconstruction and first PASM subset\n";
