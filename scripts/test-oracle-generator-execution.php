<?php

declare(strict_types=1);

// Proof facets exercised by this parity test: yield_value generator_send generator_current generator_next generator_return yield_from_delegation delegated_yields delegated_return

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleGeneratorExecutor.php';

use jinx\oracle\OracleGeneratorExecutor;
use jinx\oracle\OracleProgramCompiler;

function fail(string $message): never { fwrite(STDERR, "FAIL: {$message}" . PHP_EOL); exit(1); }
function same(mixed $actual, mixed $expected, string $label): void { if ($actual !== $expected) fail($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }

function run_php_generator(string $fixture): array {
    $ret=null; $ec=null; $em=null; ob_start();
    try { $ret=require $fixture; } catch (Throwable $e) { $ec=$e::class; $em=$e->getMessage(); }
    finally { $out=(string)ob_get_clean(); }
    return ['output'=>$out,'return'=>$ret,'error_class'=>$ec,'error_message'=>$em];
}

function run_oracle_generator(string $fixture): array {
    $r=['output'=>'','return'=>null,'error_class'=>null,'error_message'=>null,'oracle'=>null,'program'=>null];
    try {
        $p=OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
        $o=OracleGeneratorExecutor::execute($p);
        $r['program']=$p; $r['oracle']=$o; $r['output']=$o['output']??null; $r['return']=$o['return']??null;
    } catch (Throwable $e) {
        $r['error_class']=$e::class; $r['error_message']=$e->getMessage();
    }
    return $r;
}

$fixtures = [
    dirname(__DIR__) . '/fixtures/oracle-executable-generator-send.php',
    dirname(__DIR__) . '/fixtures/oracle-executable-generator-yield-from.php',
];

foreach ($fixtures as $fixture) {
    $php=run_php_generator($fixture);
    $oracle=run_oracle_generator($fixture);

    same($oracle['error_class'],$php['error_class'],'Oracle error class matches PHP for ' . basename($fixture));
    if($php['error_class']!==null){
        if(!str_contains((string)$oracle['error_message'],(string)$php['error_message'])) fail('Oracle error message does not include PHP error message');
        continue;
    }

    same($oracle['output'],$php['output'],'Oracle output matches PHP for ' . basename($fixture));
    same($oracle['return'],$php['return'],'Oracle return matches PHP for ' . basename($fixture));
    same($oracle['oracle']['family']??null,'generators','Oracle execution family');
    if(($oracle['oracle']['executed_ops']??0)<6) fail('Oracle executed too few generator ops');
}

$sendSource=(string)file_get_contents($fixtures[0]);
foreach(['yield \'ready\'','->send(5)','->current()','->next()','->getReturn()'] as $needle){ if(!str_contains($sendSource,$needle)) fail("send fixture missing {$needle}"); }

$fromSource=(string)file_get_contents($fixtures[1]);
foreach(['yield from edgeInnerGenerator()','yield 1','yield 2','return 7','->getReturn()'] as $needle){ if(!str_contains($fromSource,$needle)) fail("yield-from fixture missing {$needle}"); }

echo "PASS: Oracle executes generator PHP subsets and matches PHP output/return/error behavior" . PHP_EOL;
