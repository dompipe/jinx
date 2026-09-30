<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function uploadFail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function uploadRun(string $command, ?int &$code = null): string
{
    $out = [];
    $status = 0;
    exec($command . ' 2>&1', $out, $status);
    $code = $status;
    return rtrim(implode(PHP_EOL, $out), "\r\n");
}

if (!is_file($jinx) || !is_executable($jinx)) {
    uploadFail('repository-root native ./jinx missing or not executable');
}

$source = tempnam(sys_get_temp_dir(), 'jinx-upload-');
if ($source === false) uploadFail('could not create upload parity source');
$dest = $source . '.moved';
@unlink($dest);
file_put_contents($source, 'jinx-upload-parity');

$phpUploaded = is_uploaded_file($source);
$phpMoved = move_uploaded_file($source, $dest);
$phpSourceExists = file_exists($source);
$phpDestExists = file_exists($dest);

$nativeUploaded = uploadRun(
    escapeshellarg($jinx)
        . ' oracle-call is_uploaded_file '
        . escapeshellarg('s:' . $source),
    $uploadedCode
);
$nativeMoved = uploadRun(
    escapeshellarg($jinx)
        . ' oracle-call move_uploaded_file '
        . escapeshellarg('s:' . $source)
        . ' '
        . escapeshellarg('s:' . $dest),
    $movedCode
);

$nativeSourceExists = file_exists($source);
$nativeDestExists = file_exists($dest);

@unlink($source);
@unlink($dest);

$expectedUploaded = 'bool:' . ($phpUploaded ? 'true' : 'false');
$expectedMoved = 'bool:' . ($phpMoved ? 'true' : 'false');

if ($uploadedCode !== 0 || $nativeUploaded !== $expectedUploaded) {
    uploadFail(
        "is_uploaded_file parity mismatch\n" .
        "PHP/expected: {$expectedUploaded}\nJINX: {$nativeUploaded}"
    );
}
if ($movedCode !== 0 || $nativeMoved !== $expectedMoved) {
    uploadFail(
        "move_uploaded_file parity mismatch\n" .
        "PHP/expected: {$expectedMoved}\nJINX: {$nativeMoved}"
    );
}
if ($nativeSourceExists !== $phpSourceExists ||
    $nativeDestExists !== $phpDestExists) {
    uploadFail(
        "move_uploaded_file filesystem side-effect mismatch\n" .
        'PHP source=' . ($phpSourceExists ? 'true' : 'false') .
        ' dest=' . ($phpDestExists ? 'true' : 'false') . "\n" .
        'JINX source=' . ($nativeSourceExists ? 'true' : 'false') .
        ' dest=' . ($nativeDestExists ? 'true' : 'false')
    );
}

echo "PASS: native empty upload registry matches PHP CLI file semantics", PHP_EOL;
