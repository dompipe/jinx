<?php

declare(strict_types=1);

// Proof facets exercised by this parity test: magic_set magic_get magic_isset magic_unset magic_call magic_call_static missing_member_dispatch

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleMagicMethodExecutor.php';

use jinx\oracle\OracleMagicMethodExecutor;
use jinx\oracle\OracleProgramCompiler;

function fail(string $message): never { fwrite(STDERR, "FAIL: {$message}" . PHP_EOL); exit(1); }
function same(mixed $actual, mixed $expected, string $label): void { if ($actual !== $expected) fail($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }

$fixture = dirname(__DIR__) . '/fixtures/oracle-executable-magic-methods.php';

function run_php_magic(string $fixture): array {
    $ret=null; $ec=null; $em=null; ob_start();
    try { $ret=require $fixture; } catch (Throwable $e) { $ec=$e::class; $em=$e->getMessage(); }
    finally { $out=(string)ob_get_clean(); }
    return ['output'=>$out,'return'=>$ret,'error_class'=>$ec,'error_message'=>$em];
}
function run_oracle_magic(string $fixture): array {
    $r=['output'=>'','return'=>null,'error_class'=>null,'error_message'=>null,'oracle'=>null,'program'=>null];
    try {
        $p=OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
        $o=OracleMagicMethodExecutor::execute($p);
        $r['program']=$p; $r['oracle']=$o; $r['output']=$o['output']??null; $r['return']=$o['return']??null;
    } catch (Throwable $e) { $r['error_class']=$e::class; $r['error_message']=$e->getMessage(); }
    return $r;
}

$php=run_php_magic($fixture); $oracle=run_oracle_magic($fixture);
same($oracle['error_class'],$php['error_class'],'Oracle error class matches PHP');
if($php['error_class']!==null){ if(!str_contains((string)$oracle['error_message'],(string)$php['error_message'])) fail('Oracle error message does not include PHP error message'); exit(0); }
same($oracle['output'],$php['output'],'Oracle output matches PHP');
same($oracle['return'],$php['return'],'Oracle return matches PHP');
same($oracle['oracle']['family']??null,'magic-methods','Oracle execution family');
if(($oracle['oracle']['executed_ops']??0)<9) fail('Oracle executed too few magic-method ops');
$source=(string)file_get_contents($fixture);
foreach(['__set','__get','__isset','__unset','__call','__callStatic'] as $needle){ if(!str_contains($source,$needle)) fail("fixture missing {$needle}"); }
echo "PASS: Oracle executes magic method PHP subset and matches PHP output/return/error behavior" . PHP_EOL;
