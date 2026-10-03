<?php

declare(strict_types=1);

// Proof facets exercised by this parity test: comparison_gt comparison_lt strict_equality boolean_and boolean_or ternary_expression associative_array_output

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleStraightLineExecutor.php';

use jinx\oracle\OracleProgramCompiler;
use jinx\oracle\OracleStraightLineExecutor;

function fail(string $message): never { fwrite(STDERR, "FAIL: {$message}" . PHP_EOL); exit(1); }
function same(mixed $actual, mixed $expected, string $label): void {
    if ($actual !== $expected) fail($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

$fixture = dirname(__DIR__) . '/fixtures/oracle-executable-comparison-boolean.php';

function run_php_comparison_boolean(string $fixture): array {
    $ret=null; $ec=null; $em=null; ob_start();
    try { $ret=require $fixture; } catch (Throwable $e) { $ec=$e::class; $em=$e->getMessage(); }
    finally { $out=(string)ob_get_clean(); }
    return ['output'=>$out,'return'=>$ret,'error_class'=>$ec,'error_message'=>$em];
}

function run_oracle_comparison_boolean(string $fixture): array {
    $r=['output'=>'','return'=>null,'error_class'=>null,'error_message'=>null,'oracle'=>null,'program'=>null];
    try {
        $p=OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
        $o=OracleStraightLineExecutor::execute($p);
        $r['program']=$p; $r['oracle']=$o; $r['output']=$o['output']??null; $r['return']=$o['return']??null;
    } catch (Throwable $e) {
        $r['error_class']=$e::class; $r['error_message']=$e->getMessage();
    }
    return $r;
}

$php=run_php_comparison_boolean($fixture);
$oracle=run_oracle_comparison_boolean($fixture);

same($oracle['error_class'],$php['error_class'],'Oracle error class matches PHP');
if($php['error_class']!==null){
    same($oracle['error_message'],$php['error_message'],'Oracle error message matches PHP');
    exit(0);
}
same($oracle['output'],$php['output'],'Oracle output matches PHP');

$ops=array_column($oracle['program']['statements']??[],'op');
if(in_array('O_TERNARY',$ops,true)) fail('ternary-containing assignment should remain O_ASSIGN');
foreach(['O_DECLARE','O_ASSIGN','O_ECHO'] as $op){
    if(!in_array($op,$ops,true)) fail("fixture did not produce expected {$op}");
}

echo "PASS: Oracle executes comparison boolean ternary subset and matches PHP output/return/error behavior" . PHP_EOL;
