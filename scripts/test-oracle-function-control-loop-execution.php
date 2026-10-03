<?php

declare(strict_types=1);

// Proof facets exercised by this parity test: function_for_loop function_array_append function_ternary function_modulo function_comparison function_implode function_json_encode

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleFunctionExecutor.php';

use jinx\oracle\OracleFunctionExecutor;
use jinx\oracle\OracleProgramCompiler;

function fail(string $message): never { fwrite(STDERR, "FAIL: {$message}" . PHP_EOL); exit(1); }
function same(mixed $actual, mixed $expected, string $label): void {
    if ($actual !== $expected) fail($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

$fixture = dirname(__DIR__) . '/fixtures/oracle-executable-function-control-loop.php';

function run_php_function_control(string $fixture): array {
    $ret=null; $ec=null; $em=null; ob_start();
    try { $ret=require $fixture; } catch (Throwable $e) { $ec=$e::class; $em=$e->getMessage(); }
    finally { $out=(string)ob_get_clean(); }
    return ['output'=>$out,'return'=>$ret,'error_class'=>$ec,'error_message'=>$em];
}

function run_oracle_function_control(string $fixture): array {
    $r=['output'=>'','return'=>null,'error_class'=>null,'error_message'=>null,'oracle'=>null,'program'=>null];
    try {
        $p=OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
        $o=OracleFunctionExecutor::execute($p);
        $r['program']=$p; $r['oracle']=$o; $r['output']=$o['output']??null; $r['return']=$o['return']??null;
    } catch (Throwable $e) {
        $r['error_class']=$e::class; $r['error_message']=$e->getMessage();
    }
    return $r;
}

$php=run_php_function_control($fixture);
$oracle=run_oracle_function_control($fixture);

same($oracle['error_class'],$php['error_class'],'Oracle error class matches PHP');
if($php['error_class']!==null){
    same($oracle['error_message'],$php['error_message'],'Oracle error message matches PHP');
    exit(0);
}
same($oracle['output'],$php['output'],'Oracle output matches PHP');
same($oracle['return'],$php['return'],'Oracle return matches PHP');
same($oracle['oracle']['family']??null,'functions','Oracle execution family');

$ops=array_column($oracle['program']['statements']??[],'op');
foreach(['O_FUNCTION_DECL','O_ASSIGN','O_FOR','O_DIM_ASSIGN','O_BLOCK_CLOSE','O_RETURN','O_ECHO'] as $op){
    if(!in_array($op,$ops,true)) fail("fixture did not produce expected {$op}");
}

echo "PASS: Oracle executes function control-loop subset and matches PHP output/return/error behavior" . PHP_EOL;
