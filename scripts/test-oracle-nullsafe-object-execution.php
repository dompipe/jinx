<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleModernObjectExecutor.php';

use jinx\oracle\OracleModernObjectExecutor;
use jinx\oracle\OracleProgramCompiler;

function fail(string $message): never { fwrite(STDERR, "FAIL: {$message}" . PHP_EOL); exit(1); }
function same(mixed $actual, mixed $expected, string $label): void { if ($actual !== $expected) fail($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }

$fixture = dirname(__DIR__) . '/fixtures/oracle-executable-nullsafe-objects.php';

function run_php_nullsafe(string $fixture): array {
    $ret=null; $ec=null; $em=null; ob_start();
    try { $ret=require $fixture; } catch (Throwable $e) { $ec=$e::class; $em=$e->getMessage(); }
    finally { $out=(string)ob_get_clean(); }
    return ['output'=>$out,'return'=>$ret,'error_class'=>$ec,'error_message'=>$em];
}
function run_oracle_nullsafe(string $fixture): array {
    $r=['output'=>'','return'=>null,'error_class'=>null,'error_message'=>null,'oracle'=>null,'program'=>null];
    try {
        $p=OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
        $o=OracleModernObjectExecutor::executeNullsafe($p);
        $r['program']=$p; $r['oracle']=$o; $r['output']=$o['output']??null; $r['return']=$o['return']??null;
    } catch (Throwable $e) { $r['error_class']=$e::class; $r['error_message']=$e->getMessage(); }
    return $r;
}

$php=run_php_nullsafe($fixture); $oracle=run_oracle_nullsafe($fixture);
same($oracle['error_class'],$php['error_class'],'Oracle error class matches PHP');
if($php['error_class']!==null){ if(!str_contains((string)$oracle['error_message'],(string)$php['error_message'])) fail('Oracle error message does not include PHP error message'); exit(0); }
same($oracle['output'],$php['output'],'Oracle output matches PHP');
same($oracle['return'],$php['return'],'Oracle return matches PHP');
same($oracle['oracle']['family']??null,'nullsafe-objects','Oracle execution family');
if(($oracle['oracle']['executed_ops']??0)<7) fail('Oracle executed too few nullsafe ops');
$ops=array_column($oracle['program']['statements']??[],'op');
foreach(['O_NULLSAFE_CALL','O_NULLSAFE_PROPERTY_FETCH'] as $op){ if(!in_array($op,$ops,true)) fail("fixture did not produce expected {$op}"); }
echo "PASS: Oracle executes nullsafe object PHP subset and matches PHP output/return/error behavior" . PHP_EOL;
