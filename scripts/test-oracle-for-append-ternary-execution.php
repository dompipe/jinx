<?php

declare(strict_types=1);

// Proof facets exercised by this parity test: for_header_semicolons array_append ternary_branch modulo_expression interpolated_loop_string implode_output

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleForExecutor.php';

use jinx\oracle\OracleForExecutor;
use jinx\oracle\OracleProgramCompiler;

function fail(string $message): never { fwrite(STDERR, "FAIL: {$message}" . PHP_EOL); exit(1); }
function same(mixed $actual, mixed $expected, string $label): void {
    if ($actual !== $expected) fail($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

$fixture = dirname(__DIR__) . '/fixtures/oracle-executable-for-append-ternary.php';

function run_php_for_append(string $fixture): array {
    $ret=null; $ec=null; $em=null; ob_start();
    try { $ret=require $fixture; } catch (Throwable $e) { $ec=$e::class; $em=$e->getMessage(); }
    finally { $out=(string)ob_get_clean(); }
    return ['output'=>$out,'return'=>$ret,'error_class'=>$ec,'error_message'=>$em];
}

function run_oracle_for_append(string $fixture): array {
    $r=['output'=>'','return'=>null,'error_class'=>null,'error_message'=>null,'oracle'=>null,'program'=>null];
    try {
        $p=OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
        $o=OracleForExecutor::execute($p);
        $r['program']=$p; $r['oracle']=$o; $r['output']=$o['output']??null; $r['return']=$o['return']??null;
    } catch (Throwable $e) {
        $r['error_class']=$e::class; $r['error_message']=$e->getMessage();
    }
    return $r;
}

$php=run_php_for_append($fixture);
$oracle=run_oracle_for_append($fixture);

same($oracle['error_class'],$php['error_class'],'Oracle error class matches PHP');
if ($php['error_class'] !== null) {
    same($oracle['error_message'],$php['error_message'],'Oracle error message matches PHP');
    exit(0);
}
same($oracle['output'],$php['output'],'Oracle output matches PHP');
same($oracle['return'],$php['return'],'Oracle return matches PHP');
same($oracle['oracle']['family']??null,'for-loops','Oracle execution family');

$ops=array_column($oracle['program']['statements']??[],'op');
if (in_array('O_RAW_PHP_STMT',$ops,true)) fail('for append ternary fixture still records raw PHP statement');
foreach(['O_FOR','O_DIM_ASSIGN','O_BLOCK_CLOSE','O_ECHO','O_RETURN'] as $op){
    if(!in_array($op,$ops,true)) fail("fixture did not produce expected {$op}");
}

echo "PASS: Oracle executes for-loop array append ternary subset and matches PHP output/return/error behavior" . PHP_EOL;
