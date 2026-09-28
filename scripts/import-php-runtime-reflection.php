<?php
declare(strict_types=1);

/**
 * Import the callables exposed by the current PHP runtime through Reflection.
 *
 * This is a fallback/complement to import-php-src-stubs.php. php-src stubs remain
 * the preferred canonical source, but Reflection lets the project immediately
 * build a broad callable inventory when a full php-src checkout is unavailable.
 *
 * Usage:
 *   php scripts/import-php-runtime-reflection.php [output.json]
 */

$out = $argv[1] ?? (dirname(__DIR__) . '/spec/php-functions.from-runtime.json');
$functions = [];

foreach (get_defined_functions()['internal'] ?? [] as $name) {
    try {
        $ref = new ReflectionFunction($name);
    } catch (Throwable) {
        continue;
    }
    $entry = reflection_callable_entry($ref, null);
    $functions[strtolower($entry['name'])] = $entry;
}

foreach (get_declared_classes() as $className) {
    try {
        $class = new ReflectionClass($className);
    } catch (Throwable) {
        continue;
    }
    if (!$class->isInternal()) {
        continue;
    }
    foreach ($class->getMethods() as $method) {
        if (!$method->isInternal()) {
            continue;
        }
        $entry = reflection_callable_entry($method, $class);
        $functions[strtolower($entry['name'])] = $entry;
    }
}

foreach (get_declared_interfaces() as $interfaceName) {
    try {
        $interface = new ReflectionClass($interfaceName);
    } catch (Throwable) {
        continue;
    }
    if (!$interface->isInternal()) {
        continue;
    }
    foreach ($interface->getMethods() as $method) {
        if (!$method->isInternal()) {
            continue;
        }
        $entry = reflection_callable_entry($method, $interface);
        $functions[strtolower($entry['name'])] = $entry;
    }
}

ksort($functions, SORT_STRING);
$manifest = [
    'manifest' => [
        'name' => 'jinx-php-functions-from-runtime-reflection',
        'version' => '0.3',
        'source' => 'Imported from the current PHP runtime with Reflection. Use php-src stubs for the final canonical manifest.',
        'generated_at' => gmdate('c'),
        'php_version' => PHP_VERSION,
        'doctrine' => 'Runtime Reflection imports are broad inventory only. A callable is not implemented until PHP behavior is known, JINX/Oracle mirroring exists, fallback or fail-closed behavior is defined, and parity tests pass. PASM/native lowering is optional later output.',
        'pasm_level' => 'oracle-lower-register-stack-label',
    ],
    'functions' => array_values($functions),
];

@mkdir(dirname($out), 0777, true);
file_put_contents($out, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo 'PASS: imported ' . count($functions) . " internal callables from PHP " . PHP_VERSION . " runtime reflection into {$out}\n";

/** @return array<string,mixed> */
function reflection_callable_entry(ReflectionFunctionAbstract $ref, ?ReflectionClass $owner): array
{
    $ownerName = $owner?->getName();
    $name = $ownerName !== null ? $ownerName . '::' . $ref->getName() : $ref->getName();
    $short = $ref->getName();
    $extension = $ref->getExtensionName() ?: ($owner?->getExtensionName() ?: 'core');
    $parameters = array_map('reflection_parameter_entry', $ref->getParameters());
    $requiredCount = 0;
    foreach ($parameters as $param) {
        if (($param['required'] ?? false) === true && ($param['variadic'] ?? false) === false) {
            $requiredCount++;
        }
    }
    return [
        'name' => $name,
        'short_name' => $short,
        'kind' => $ownerName !== null ? 'method' : 'builtin',
        'owner' => $ownerName,
        'extension' => strtolower($extension),
        'source_file' => 'runtime-reflection:' . PHP_VERSION,
        'is_static' => $ref instanceof ReflectionMethod ? $ref->isStatic() : false,
        'arity' => [
            'required' => $requiredCount,
            'total' => $ref->getNumberOfParameters(),
            'variadic' => $ref->isVariadic(),
        ],
        'parameters' => $parameters,
        'return' => ['type' => reflection_type_to_string($ref->getReturnType())],
        'pasm_lowering' => make_pasm_call_lowering($ownerName !== null ? 'method' : 'builtin', $name, $parameters),
        'native_strategy' => classify_native_strategy($name, strtolower($extension)),
        'status' => 'imported_signature',
        'pasm_profile' => 'lowered-register-stack-call',
    ];
}

/** @return array<string,mixed> */
function reflection_parameter_entry(ReflectionParameter $param): array
{
    $default = null;
    if ($param->isDefaultValueAvailable()) {
        try {
            $default = $param->isDefaultValueConstant()
                ? $param->getDefaultValueConstantName()
                : $param->getDefaultValue();
        } catch (Throwable) {
            $default = '__unavailable_default__';
        }
    }
    return [
        'name' => $param->getName(),
        'type' => reflection_type_to_string($param->getType()),
        'by_ref' => $param->isPassedByReference(),
        'variadic' => $param->isVariadic(),
        'required' => !$param->isOptional() && !$param->isVariadic(),
        'default' => $default,
    ];
}

function reflection_type_to_string(?ReflectionType $type): string
{
    if ($type === null) {
        return 'mixed';
    }
    if ($type instanceof ReflectionNamedType) {
        $name = $type->getName();
        return ($type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' : '') . $name;
    }
    if ($type instanceof ReflectionUnionType) {
        return implode('|', array_map('reflection_type_to_string', $type->getTypes()));
    }
    if ($type instanceof ReflectionIntersectionType) {
        return implode('&', array_map('reflection_type_to_string', $type->getTypes()));
    }
    return (string)$type;
}

/** @param list<array<string,mixed>> $parameters @return list<string> */
function make_pasm_call_lowering(string $kind, string $name, array $parameters): array
{
    $ops = [];
    foreach ($parameters as $index => $param) {
        $paramName = (string)($param['name'] ?? ('arg' . $index));
        $ops[] = 'LOAD_ARG R' . $index . ', ' . $paramName;
        if (($param['by_ref'] ?? false) === true && ($param['variadic'] ?? false) === true) {
            $ops[] = 'PUSH_ARG_VARIADIC_REF R' . $index;
        } elseif (($param['by_ref'] ?? false) === true) {
            $ops[] = 'PUSH_ARG_REF R' . $index;
        } elseif (($param['variadic'] ?? false) === true) {
            $ops[] = 'PUSH_ARG_VARIADIC R' . $index;
        } else {
            $ops[] = 'PUSH_ARG R' . $index;
        }
    }
    $ops[] = ($kind === 'method' ? 'CALL_METHOD_BUILTIN ' : 'CALL_BUILTIN ') . $name . ', argc=' . count($parameters);
    $ops[] = 'MOV ACC, RET';
    return $ops;
}

function classify_native_strategy(string $name, string $extension): string
{
    $short = strtolower(str_contains($name, '::') ? substr($name, strrpos($name, '::') + 2) : $name);
    $intrinsics = [
        'strlen', 'count', 'is_null', 'is_bool', 'is_int', 'is_integer', 'is_float',
        'is_double', 'is_string', 'is_array', 'is_object', 'is_resource', 'ord', 'chr',
    ];
    if (in_array($short, $intrinsics, true)) {
        return 'intrinsic';
    }
    if (in_array($extension, ['standard', 'spl', 'date', 'pcre', 'json', 'hash', 'random'], true)) {
        return 'runtime_helper';
    }
    if (in_array($extension, ['mysqli', 'pdo', 'curl', 'intl', 'openssl', 'sockets', 'sqlite3'], true)) {
        return 'extension_bridge';
    }
    return 'runtime_helper_or_extension_bridge';
}
