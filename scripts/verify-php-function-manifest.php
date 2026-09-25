<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$path = $argv[1] ?? ($root . '/spec/php-functions.seed.json');
$json = json_decode(file_get_contents($path) ?: '', true, 512, JSON_THROW_ON_ERROR);

$requiredFunctionKeys = ['name', 'kind', 'extension', 'parameters', 'return', 'pasm_lowering', 'native_strategy', 'status'];
$requiredParamKeys = ['name', 'type', 'by_ref', 'variadic', 'required', 'default'];
$names = [];
$errors = [];

if (($json['manifest']['doctrine'] ?? '') === '') {
    $errors[] = 'manifest.doctrine is required';
}

foreach ($json['functions'] ?? [] as $index => $function) {
    foreach ($requiredFunctionKeys as $key) {
        if (!array_key_exists($key, $function)) {
            $errors[] = "functions[$index] missing {$key}";
        }
    }
    $name = (string)($function['name'] ?? '');
    if ($name === '' || !preg_match('#^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*(?:::[A-Za-z_][A-Za-z0-9_]*)?$#', $name)) {
        $errors[] = "functions[$index] has invalid name {$name}";
    }
    $lower = strtolower($name);
    if (isset($names[$lower])) {
        $errors[] = "duplicate function {$name}";
    }
    $names[$lower] = true;

    if (!is_array($function['parameters'] ?? null)) {
        $errors[] = "{$name} parameters must be an array";
        continue;
    }
    $seenOptional = false;
    foreach ($function['parameters'] as $paramIndex => $param) {
        foreach ($requiredParamKeys as $key) {
            if (!array_key_exists($key, $param)) {
                $errors[] = "{$name} parameter[$paramIndex] missing {$key}";
            }
        }
        if (($param['required'] ?? false) === false) {
            $seenOptional = true;
        }
        if ($seenOptional && ($param['required'] ?? false) === true && ($param['variadic'] ?? false) === false) {
            $errors[] = "{$name} has required parameter after optional parameter at index {$paramIndex}";
        }
        if (($param['variadic'] ?? false) === true && $paramIndex !== count($function['parameters']) - 1) {
            $errors[] = "{$name} variadic parameter must be last";
        }
    }
    if (!is_array($function['pasm_lowering'] ?? null) || count($function['pasm_lowering']) < 1) {
        $errors[] = "{$name} must declare at least one PASM lowering operation in this seed manifest profile";
    }
}

if ($errors !== []) {
    fwrite(STDERR, "PHP_FUNCTION_MANIFEST_ERROR:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

$statusCounts = [];
foreach ($json['functions'] ?? [] as $fn) {
    $status = (string)($fn['status'] ?? 'unknown');
    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
}
echo 'PASS: PHP function manifest shape verified for ' . count($json['functions'] ?? []) . ' callables';
if ($statusCounts !== []) {
    echo ' (';
    $chunks = [];
    foreach ($statusCounts as $status => $count) {
        $chunks[] = $status . '=' . $count;
    }
    echo implode(', ', $chunks) . ')';
}
echo "\n";
