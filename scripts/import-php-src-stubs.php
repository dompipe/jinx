<?php
declare(strict_types=1);

/**
 * Import PHP built-in functions/methods from a local php-src checkout.
 *
 * Usage:
 *   php scripts/import-php-src-stubs.php /path/to/php-src [output.json]
 *
 * php-src treats *.stub.php files as the canonical source for internal
 * signatures/arginfo. This importer deliberately imports signatures and
 * parameter flags only; every callable still requires an explicit PASM
 * lowering/native strategy before support is considered implemented.
 */

$root = $argv[1] ?? null;
$out = $argv[2] ?? (dirname(__DIR__) . '/spec/php-functions.from-php-src.json');
if ($root === null || !is_dir($root)) {
    fwrite(STDERR, "Usage: php scripts/import-php-src-stubs.php /path/to/php-src [output.json]\n");
    exit(2);
}

$root = realpath($root) ?: $root;
$files = find_stub_files($root);
if ($files === []) {
    fwrite(STDERR, "No *.stub.php files found under {$root}\n");
    exit(1);
}

$callables = [];
foreach ($files as $file) {
    foreach (extract_stub_callables($file, $root) as $entry) {
        $key = strtolower($entry['name']);
        if (!isset($callables[$key])) {
            $callables[$key] = $entry;
        }
    }
}
ksort($callables, SORT_STRING);

$manifest = [
    'manifest' => [
        'name' => 'jinx-php-functions-from-php-src',
        'version' => '0.2',
        'source' => 'Imported from php-src *.stub.php files. Stubs are the canonical arginfo source inside php-src.',
        'source_root' => $root,
        'generated_at' => gmdate('c'),
        'doctrine' => 'Imported signatures are inventory only. A callable is not implemented until PASM lowering, evaluator behavior, native strategy, and parity tests exist.',
        'pasm_level' => 'oracle-lower-register-stack-label',
    ],
    'functions' => array_values($callables),
];

@mkdir(dirname($out), 0777, true);
file_put_contents($out, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo 'PASS: imported ' . count($callables) . ' callables from ' . count($files) . " php-src stub files into {$out}\n";

/** @return list<string> */
function find_stub_files(string $root): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $info) {
        if (!$info->isFile()) {
            continue;
        }
        $path = $info->getPathname();
        if (str_ends_with($path, '.stub.php')) {
            $files[] = $path;
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

/** @return list<array<string,mixed>> */
function extract_stub_callables(string $file, string $root): array
{
    $src = file_get_contents($file);
    if ($src === false) {
        return [];
    }
    $tokens = token_get_all($src);
    $entries = [];
    $namespace = '';
    $pendingClass = null;
    $classStack = [];
    $braceDepth = 0;
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $tok = $tokens[$i];
        $id = is_array($tok) ? $tok[0] : null;
        $text = is_array($tok) ? $tok[1] : $tok;

        if ($id === T_NAMESPACE) {
            [$namespace, $i] = parse_namespace($tokens, $i + 1);
            continue;
        }

        if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
            $pendingClass = next_named_token($tokens, $i + 1);
            if ($pendingClass !== null && $namespace !== '') {
                $pendingClass = $namespace . '\\' . $pendingClass;
            }
            continue;
        }

        if ($text === '{') {
            $braceDepth++;
            if ($pendingClass !== null) {
                $classStack[] = ['name' => $pendingClass, 'depth' => $braceDepth];
                $pendingClass = null;
            }
            continue;
        }

        if ($text === '}') {
            while ($classStack !== [] && end($classStack)['depth'] >= $braceDepth) {
                array_pop($classStack);
            }
            $braceDepth = max(0, $braceDepth - 1);
            continue;
        }

        if ($id === T_FUNCTION) {
            $parsed = parse_function_signature($tokens, $i + 1);
            if ($parsed === null) {
                continue;
            }
            $i = $parsed['end_index'];
            $owner = $classStack !== [] ? end($classStack)['name'] : null;
            $baseName = $parsed['name'];
            $fqName = $owner !== null
                ? $owner . '::' . $baseName
                : (($namespace !== '') ? $namespace . '\\' . $baseName : $baseName);
            $entries[] = [
                'name' => $fqName,
                'short_name' => $baseName,
                'kind' => $owner !== null ? 'method' : 'builtin',
                'owner' => $owner,
                'extension' => infer_extension($file, $root),
                'source_file' => relative_path($file, $root),
                'parameters' => ($parameters = parse_parameters($parsed['params'])),
                'return' => ['type' => $parsed['return_type'] ?: 'mixed'],
                'pasm_lowering' => make_pasm_call_lowering($owner !== null ? 'method' : 'builtin', $fqName, $parameters),
                'native_strategy' => classify_native_strategy($fqName, infer_extension($file, $root)),
                'status' => 'imported_signature',
                'pasm_profile' => 'lowered-register-stack-call',
            ];
        }
    }
    return $entries;
}

/** @return array{0:string,1:int} */
function parse_namespace(array $tokens, int $i): array
{
    $parts = [];
    for ($n = count($tokens); $i < $n; $i++) {
        $tok = $tokens[$i];
        $text = is_array($tok) ? $tok[1] : $tok;
        if ($text === ';' || $text === '{') {
            break;
        }
        if (is_array($tok) && in_array($tok[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
            $parts[] = $text;
        }
    }
    return [trim(implode('', $parts), '\\'), $i];
}

function next_named_token(array $tokens, int $i): ?string
{
    for ($n = count($tokens); $i < $n; $i++) {
        $tok = $tokens[$i];
        if (is_array($tok) && $tok[0] === T_STRING) {
            return $tok[1];
        }
        $text = is_array($tok) ? $tok[1] : $tok;
        if ($text === '{' || $text === '(' || $text === ';') {
            return null;
        }
    }
    return null;
}

/** @return array{name:string,params:string,return_type:string,end_index:int}|null */
function parse_function_signature(array $tokens, int $i): ?array
{
    $n = count($tokens);
    for (; $i < $n; $i++) {
        $tok = $tokens[$i];
        $text = is_array($tok) ? $tok[1] : $tok;
        if ($text === '&' || (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
            continue;
        }
        if (!is_array($tok) || $tok[0] !== T_STRING) {
            return null; // closure or malformed for our manifest purposes
        }
        $name = $tok[1];
        break;
    }
    for (; $i < $n; $i++) {
        $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        if ($text === '(') {
            break;
        }
    }
    if ($i >= $n) {
        return null;
    }
    [$params, $i] = collect_balanced($tokens, $i, '(', ')');
    $return = '';
    for ($i++; $i < $n; $i++) {
        $tok = $tokens[$i];
        $text = is_array($tok) ? $tok[1] : $tok;
        if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        if ($text === ':') {
            [$return, $i] = collect_until_body($tokens, $i + 1);
            break;
        }
        if ($text === ';' || $text === '{') {
            break;
        }
    }
    return ['name' => $name, 'params' => $params, 'return_type' => normalize_type($return), 'end_index' => $i];
}

/** @return array{0:string,1:int} */
function collect_balanced(array $tokens, int $i, string $open, string $close): array
{
    $depth = 0;
    $out = '';
    for ($n = count($tokens); $i < $n; $i++) {
        $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        if ($text === $open) {
            $depth++;
            if ($depth === 1) {
                continue;
            }
        }
        if ($text === $close) {
            $depth--;
            if ($depth === 0) {
                return [$out, $i];
            }
        }
        $out .= $text;
    }
    return [$out, $i];
}

/** @return array{0:string,1:int} */
function collect_until_body(array $tokens, int $i): array
{
    $out = '';
    for ($n = count($tokens); $i < $n; $i++) {
        $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        if ($text === ';' || $text === '{') {
            return [$out, $i];
        }
        $out .= $text;
    }
    return [$out, $i];
}

/** @return list<array<string,mixed>> */
function parse_parameters(string $params): array
{
    $parts = split_top_level_commas($params);
    $out = [];
    foreach ($parts as $raw) {
        $raw = trim($raw);
        if ($raw === '') {
            continue;
        }
        $raw = preg_replace('/#\[.*?\]/s', '', $raw) ?? $raw;
        $default = null;
        $required = true;
        [$beforeDefault, $defaultText, $hasDefault] = split_default($raw);
        if ($hasDefault) {
            $required = false;
            $default = normalize_default($defaultText);
        }
        $variadic = str_contains($beforeDefault, '...');
        $byRef = preg_match('/&\s*\$[A-Za-z_][A-Za-z0-9_]*/', $beforeDefault) === 1;
        if (!preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)/', $beforeDefault, $m)) {
            continue;
        }
        $name = $m[1];
        $type = trim(preg_replace('/\.\.\.|&|\$[A-Za-z_][A-Za-z0-9_]*|=.+$/s', '', $beforeDefault) ?? '');
        $type = normalize_type($type);
        $out[] = [
            'name' => $name,
            'type' => $type !== '' ? $type : 'mixed',
            'by_ref' => $byRef,
            'variadic' => $variadic,
            'required' => $required,
            'default' => $default,
        ];
    }
    return $out;
}

/** @return list<string> */
function split_top_level_commas(string $text): array
{
    $out = [];
    $buf = '';
    $depth = 0;
    $quote = null;
    $len = strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $ch = $text[$i];
        if ($quote !== null) {
            $buf .= $ch;
            if ($ch === $quote && ($i === 0 || $text[$i - 1] !== '\\')) {
                $quote = null;
            }
            continue;
        }
        if ($ch === '"' || $ch === "'") {
            $quote = $ch;
            $buf .= $ch;
            continue;
        }
        if ($ch === '[' || $ch === '(') {
            $depth++;
        } elseif ($ch === ']' || $ch === ')') {
            $depth--;
        } elseif ($ch === ',' && $depth === 0) {
            $out[] = $buf;
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    $out[] = $buf;
    return $out;
}

/** @return array{0:string,1:string,2:bool} */
function split_default(string $raw): array
{
    $depth = 0;
    $quote = null;
    $len = strlen($raw);
    for ($i = 0; $i < $len; $i++) {
        $ch = $raw[$i];
        if ($quote !== null) {
            if ($ch === $quote && ($i === 0 || $raw[$i - 1] !== '\\')) {
                $quote = null;
            }
            continue;
        }
        if ($ch === '"' || $ch === "'") {
            $quote = $ch;
            continue;
        }
        if ($ch === '[' || $ch === '(') {
            $depth++;
        } elseif ($ch === ']' || $ch === ')') {
            $depth--;
        } elseif ($ch === '=' && $depth === 0) {
            return [trim(substr($raw, 0, $i)), trim(substr($raw, $i + 1)), true];
        }
    }
    return [$raw, '', false];
}

function normalize_default(string $text): mixed
{
    $lower = strtolower(trim($text));
    return match ($lower) {
        'null' => null,
        'true' => true,
        'false' => false,
        default => is_numeric($text) ? (str_contains($text, '.') ? (float)$text : (int)$text) : trim($text),
    };
}

function normalize_type(string $type): string
{
    $type = trim(preg_replace('/\s+/', '', $type) ?? '');
    $type = trim($type, '\\');
    return $type;
}

function infer_extension(string $file, string $root): string
{
    $rel = str_replace('\\', '/', relative_path($file, $root));
    if (preg_match('#^ext/([^/]+)/#', $rel, $m)) {
        return $m[1];
    }
    if (str_starts_with($rel, 'Zend/')) {
        return 'zend';
    }
    if (str_starts_with($rel, 'main/')) {
        return 'main';
    }
    return 'core';
}

/** @param list<array<string,mixed>> $parameters @return list<string> */
function make_pasm_call_lowering(string $kind, string $name, array $parameters): array
{
    $ops = [];
    foreach ($parameters as $index => $param) {
        $paramName = (string)($param['name'] ?? ('arg' . $index));
        $ops[] = 'LOAD_ARG R' . $index . ', ' . $paramName;
        if (($param['by_ref'] ?? false) === true) {
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
    $intrinsics = ['strlen', 'count', 'is_null', 'is_bool', 'is_int', 'is_integer', 'is_float', 'is_string', 'is_array'];
    if (in_array($short, $intrinsics, true)) {
        return 'intrinsic';
    }
    if (in_array($extension, ['standard', 'spl', 'date', 'pcre', 'json'], true)) {
        return 'runtime_helper';
    }
    if (in_array($extension, ['mysqli', 'pdo', 'curl', 'intl', 'openssl', 'sockets'], true)) {
        return 'extension_bridge';
    }
    return 'runtime_helper_or_extension_bridge';
}

function relative_path(string $path, string $root): string
{
    $path = str_replace('\\', '/', realpath($path) ?: $path);
    $root = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/') . '/';
    return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
}
