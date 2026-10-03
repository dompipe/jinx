<?php
declare(strict_types=1);
error_reporting(E_ALL);

$path = tempnam(sys_get_temp_dir(), 'jinx-native-include-');
file_put_contents($path, <<<'INC'
<?php
$local += 2;
return $local * 3;
INC);
$local = 5;
$result = include $path;
unlink($path);
echo json_encode(['local' => $local, 'result' => $result]) . "\n";

$path = tempnam(sys_get_temp_dir(), 'jinx-native-once-');
file_put_contents($path, <<<'INC'
<?php
$GLOBALS['edge_once_count'] = ($GLOBALS['edge_once_count'] ?? 0) + 1;
return $GLOBALS['edge_once_count'];
INC);
$edge_once_count = 0;
$a = include_once $path;
$b = include_once $path;
unlink($path);
echo json_encode([$a, $b, $edge_once_count]) . "\n";

$path = tempnam(sys_get_temp_dir(), 'jinx-native-stream-');
$handle = fopen($path, 'wb+');
fwrite($handle, 'abc');
rewind($handle);
$data = fread($handle, 3);
fclose($handle);
unlink($path);
echo json_encode(['data' => $data, 'exists' => file_exists($path)]) . "\n";
$missing = @file_get_contents($path);
echo json_encode(['type' => gettype($missing), 'value' => $missing]) . "\n";
