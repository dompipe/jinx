<?php

declare(strict_types=1);

// Proof facets exercised by this parity test: final_class constructor_property_promotion promoted_private_property typed_method_return this_property_fetch direct_method_echo

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleObjectExecutor.php';

use jinx\oracle\OracleObjectExecutor;
use jinx\oracle\OracleProgramCompiler;

function fail(string $message): never { fwrite(STDERR, "FAIL: {$message}" . PHP_EOL); exit(1); }
function same(mixed $actual, mixed $expected, string $label): void {
    if ($actual !== $expected) fail($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

$fixture = dirname(__DIR__) . '/fixtures/oracle-executable-object-promoted.php';

function run_php_promoted_object(string $fixture): array {
    $ret=null; $ec=null; $em=null; ob_start();
    try { $ret=require $fixture; } catch (Throwable $e) { $ec=$e::class; $em=$e->getMessage(); }
    finally { $out=(string)ob_get_clean(); }
    return ['output'=>$out,'return'=>$ret,'error_class'=>$ec,'error_message'=>$em];
}

function run_oracle_promoted_object(string $fixture): array {
    $r=['output'=>'','return'=>null,'error_class'=>null,'error_message'=>null,'oracle'=>null,'program'=>null];
    try {
        $p=OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
        $o=OracleObjectExecutor::execute($p);
        $r['program']=$p; $r['oracle']=$o; $r['output']=$o['output']??null; $r['return']=$o['return']??null;
    } catch (Throwable $e) {
        $r['error_class']=$e::class; $r['error_message']=$e->getMessage();
    }
    return $r;
}

$php=run_php_promoted_object($fixture);
$oracle=run_oracle_promoted_object($fixture);

same($oracle['error_class'],$php['error_class'],'Oracle error class matches PHP');
if($php['error_class']!==null){
    same($oracle['error_message'],$php['error_message'],'Oracle error message matches PHP');
    exit(0);
}
same($oracle['output'],$php['output'],'Oracle output matches PHP');
same($oracle['return'],$php['return'],'Oracle return matches PHP');
same($oracle['oracle']['family']??null,'object-basics','Oracle execution family');

$ops=array_column($oracle['program']['statements']??[],'op');
foreach(['O_CLASS_DECL','O_METHOD_DECL','O_BLOCK_CLOSE','O_NEW','O_ECHO','O_RETURN'] as $op){
    if(!in_array($op,$ops,true)) fail("fixture did not produce expected {$op}");
}

echo "PASS: Oracle executes promoted final-class object subset and matches PHP output/return/error behavior" . PHP_EOL;
