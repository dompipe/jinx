<?php

declare(strict_types=1);

$file = 'fixtures/oracle-filesystem-read-sample.txt';
$ini = 'fixtures/oracle-filesystem-read-sample.ini';
$dir = 'fixtures';
$missing = 'fixtures/oracle-filesystem-read-missing.txt';
$glob = 'fixtures/oracle-filesystem-read-*';

$a = fileatime($file);
$b = fileowner($file);
$c = filegroup($file);
$d = is_executable($file);
$e = is_link($file);
$f = json_encode(scandir($dir));
$g = json_encode(parse_ini_file($ini));
$h = json_encode(parse_ini_file($ini, false, 2));
$i = json_encode(parse_ini_string('name = jinx\ncount = 7'));
$j = json_encode(parse_ini_string('enabled = true\ncount = 7', false, 2));
$k = getcwd();
$l = stream_resolve_include_path($file);
$m = disk_total_space($dir);
$n = file_get_contents($file, false, null, 5);
$o = file_get_contents($file, false, null, 5, 6);
$p = json_encode(file($file, 2));
$q = json_encode(glob($glob, 4));
$r = hash_file('sha512', $file);
$s = file_exists($missing);
$t = is_file($dir);

$value = json_encode([$a,$b,$c,$d,$e,$f,$g,$h,$i,$j,$k,$l,$m,$n,$o,$p,$q,$r,$s,$t]);
echo 'value=' . $value . "\n";
return $value;
