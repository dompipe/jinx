<?php
declare(strict_types=1);

/**
 * Build the JINX catalog for PHP's internal/native function surface.
 *
 * The catalog is version-specific: it reflects the PHP binary running this
 * script, then adds php.net manual links as the public reference spine.
 */

function jinxJsonSafeValue(mixed $value): mixed
{
    if (is_null($value) || is_bool($value) || is_int($value) || is_string($value)) {
        return $value;
    }
    if (is_float($value)) {
        return is_finite($value) ? $value : ['specialFloat' => (string)$value];
    }
    if (is_array($value)) {
        return array_map('jinxJsonSafeValue', $value);
    }
    if (is_object($value)) {
        return ['object' => get_class($value)];
    }
    if (is_resource($value)) {
        return ['resource' => get_resource_type($value)];
    }
    return ['unsupported' => get_debug_type($value)];
}

/** @return array<string,mixed> */
function jinxParameterSpec(ReflectionParameter $parameter): array
{
    $type = $parameter->hasType() ? (string)$parameter->getType() : null;
    $spec = [
        'name' => $parameter->getName(),
        'position' => $parameter->getPosition(),
        'type' => $type,
        'allowsNull' => $parameter->allowsNull(),
        'optional' => $parameter->isOptional(),
        'variadic' => $parameter->isVariadic(),
        'byReference' => $parameter->isPassedByReference(),
    ];
    if ($parameter->isDefaultValueAvailable()) {
        $spec['defaultAvailable'] = true;
        $spec['default'] = jinxJsonSafeValue($parameter->getDefaultValue());
    } else {
        $spec['defaultAvailable'] = false;
    }
    return $spec;
}

/** @param array<int,array<string,mixed>> $parameters */
function jinxCompilerCommandsForFunction(string $name, array $parameters): array
{
    $commands = [];
    foreach ($parameters as $parameter) {
        $commands[] = [
            'type' => 'match-argument',
            'command' => 'valuation-match',
            'argument' => $parameter['name'],
            'position' => $parameter['position'],
            'expectedType' => $parameter['type'],
            'variadic' => $parameter['variadic'],
            'byReference' => $parameter['byReference'],
            'target' => 'native-call-arg-' . $parameter['position'],
        ];
    }
    $commands[] = [
        'type' => 'call',
        'command' => 'native-php-call',
        'function' => $name,
        'status' => 'native-catalog-entry',
    ];
    return $commands;
}

function jinxManualUrl(string $function): string
{
    return 'https://www.php.net/' . str_replace('_', '-', strtolower($function));
}

function jinxMain(array $argv): int
{
    $root = dirname(__DIR__);
    $out = $argv[1] ?? ($root . '/build/php-native-commands.json');

    error_reporting(E_ALL & ~E_DEPRECATED);
    $defined = get_defined_functions();
    $functions = $defined['internal'] ?? [];
    sort($functions, SORT_STRING);

    $entries = [];
    foreach ($functions as $function) {
        try {
            $reflection = new ReflectionFunction($function);
        } catch (ReflectionException $error) {
            $entries[] = [
                'name' => $function,
                'kind' => 'internal-function',
                'manualUrl' => jinxManualUrl($function),
                'available' => false,
                'error' => $error->getMessage(),
            ];
            continue;
        }

        $parameters = array_map('jinxParameterSpec', $reflection->getParameters());
        $entries[] = [
            'name' => $function,
            'kind' => 'internal-function',
            'extension' => $reflection->getExtensionName(),
            'manualUrl' => jinxManualUrl($function),
            'available' => true,
            'returnsReference' => $reflection->returnsReference(),
            'returnType' => $reflection->hasReturnType() ? (string)$reflection->getReturnType() : null,
            'parameters' => $parameters,
            'commands' => jinxCompilerCommandsForFunction($function, $parameters),
        ];
    }

    $catalog = [
        'jinx' => 'JINX-PHP-NATIVE-COMMANDS/0.1',
        'generatedAt' => gmdate('c'),
        'php' => [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'binary' => PHP_BINARY,
            'os' => PHP_OS_FAMILY,
        ],
        'reference' => [
            'source' => 'php-runtime-reflection-plus-php-net',
            'functionIndex' => 'https://www.php.net/functions.internal',
            'manual' => 'https://www.php.net/manual/en/manual.php',
        ],
        'commandModel' => [
            'definitionOrder' => ['match-argument', 'call'],
            'nativeCallCommand' => 'native-php-call',
            'note' => 'Each branch is a compiler handoff. Function-specific PASM lowering can replace native-php-call as coverage grows.',
        ],
        'count' => count($entries),
        'commands' => $entries,
    ];

    $dir = dirname($out);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($out, json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    echo "Wrote {$out} with " . count($entries) . " PHP native commands\n";
    return 0;
}

try {
    exit(jinxMain($argv));
} catch (Throwable $error) {
    fwrite(STDERR, 'JINX_NATIVE_COMMANDS_ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
