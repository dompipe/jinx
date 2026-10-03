<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function run_case(string $name, string $body): string
{
    global $jinx, $root;

    $dir = $root . '/build/differential/oracle-builtin-routing';
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fail('could not create routing fixture directory');
    }

    $fixture = $dir . '/' . $name . '.php';
    file_put_contents(
        $fixture,
        "<?php\n\ndeclare(strict_types=1);\n\n" . $body . "\n"
    );

    $command = escapeshellarg($jinx) . ' ' . escapeshellarg($fixture);
    exec($command . ' 2>&1', $lines, $exit);
    $output = implode("\n", $lines);
    if ($lines !== []) {
        $output .= "\n";
    }

    if ($exit !== 0) {
        fail("{$name} native Jinx route failed (exit={$exit}): " . $output);
    }

    return $output;
}

$sha1 = run_case('sha1', "echo json_encode(sha1('jinx-oracle')) . \"\\n\";");
if ($sha1 !== '"fb9ca87b724d2bd3e3e1268de0662e64d08177f4"' . "\n") {
    fail('sha1 routing mismatch: ' . json_encode($sha1));
}

$round = run_case('round', "echo json_encode(round(12.55, 1)) . \"\\n\";");
if ($round !== "12.6\n") {
    fail('round routing mismatch: ' . json_encode($round));
}

echo "PASS: native Jinx routes callable fixtures through merged builtin-aware Oracle families" . PHP_EOL;
