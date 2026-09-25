<?php

declare(strict_types=1);

namespace jinx\web;

require_once __DIR__ . '/WebCompileException.php';

/**
 * Executable Web API compiler proof.
 *
 * Supported source shape:
 *
 *   $data = json_decode(file_get_contents('php://input'), true);
 *
 *   if (!isset($data['name'])) {
 *       http_response_code(400);
 *       echo json_encode(['ok' => false, 'error' => 'Missing name']);
 *       return;
 *   }
 *
 *   $name = $data['name'];
 *   echo json_encode(['ok' => true, 'name' => $name]);
 */
final class WebApiCompiler
{
    /**
     * @return array<string,mixed>
     */
    public static function compileFileToPlan(string $sourcePath): array
    {
        if (!is_file($sourcePath)) {
            throw new WebCompileException("Missing source file", $sourcePath);
        }

        try {
            return self::compileSource((string) file_get_contents($sourcePath), $sourcePath);
        } catch (WebCompileException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new WebCompileException($e->getMessage(), $sourcePath);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public static function compileSource(string $source, ?string $sourceFile = null): array
    {
        $source = trim($source);
        $baseLine = 1;

        if (str_starts_with($source, '<?php')) {
            $openTagEnd = strpos($source, "\n");

            if ($openTagEnd === false) {
                $source = '';
                $baseLine = 1;
            } else {
                $source = substr($source, $openTagEnd + 1);
                $baseLine = 2;
            }

            // Do not trim here. Preserving leading newlines keeps source line numbers correct.
        }

        $statements = self::splitStatements($source, $baseLine);
        $ops = [];

        $i = 0;

        while ($i < count($statements)) {
            $record = $statements[$i];
            $statement = $record['source'];
            $line = $record['line'];

            if ($statement === '') {
                $i++;
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*json_decode\s*\(\s*file_get_contents\s*\(\s*[\'"]php:\/\/input[\'"]\s*\)\s*,\s*true\s*\)$/', $statement, $m)) {
                $ops[] = [
                    'op' => 'WEB_READ_BODY_JSON',
                    'dst' => 'LOCAL:' . $m[1],
                    'line' => $line,
                    'source' => $statement,
                ];
                $i++;
                continue;
            }

            if (preg_match('/^if\s*\(\s*!\s*isset\s*\(\s*\$(\w+)\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]\s*\)\s*\)\s*\{$/', $statement, $m)) {
                $arrayLocal = 'LOCAL:' . $m[1];
                $key = $m[2];

                $block = [];
                $i++;

                while ($i < count($statements) && $statements[$i]['source'] !== '}') {
                    $block[] = $statements[$i];
                    $i++;
                }

                if ($i >= count($statements) || $statements[$i]['source'] !== '}') {
                    throw new WebCompileException('Unclosed if missing-key block', $sourceFile, $line, $statement);
                }

                $ops[] = [
                    'op' => 'WEB_IF_MISSING_ARRAY_KEY',
                    'array' => $arrayLocal,
                    'key' => $key,
                    'then' => self::compileBlock($block, $sourceFile),
                    'line' => $line,
                    'source' => $statement,
                ];

                $i++;
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*\$(\w+)\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]$/', $statement, $m)) {
                $ops[] = [
                    'op' => 'WEB_ARRAY_GET',
                    'dst' => 'LOCAL:' . $m[1],
                    'array' => 'LOCAL:' . $m[2],
                    'key' => $m[3],
                    'line' => $line,
                    'source' => $statement,
                ];
                $i++;
                continue;
            }

            if (preg_match('/^echo\s+json_encode\s*\(\s*\[(.*)\]\s*\)$/s', $statement, $m)) {
                $ops[] = [
                    'op' => 'WEB_ECHO_JSON_ARRAY',
                    'items' => self::parseArrayItems($m[1]),
                    'line' => $line,
                    'source' => $statement,
                ];
                $i++;
                continue;
            }

            throw new WebCompileException("Unsupported Web API statement", $sourceFile, $line, $statement);
        }

        return [
            'kind' => 'JINX_WEB_EXECUTABLE_PLAN',
            'ops' => $ops,
        ];
    }

    /**
     * @param list<string> $block
     * @return list<array<string,mixed>>
     */
    private static function compileBlock(array $block, ?string $sourceFile): array
    {
        $ops = [];

        foreach ($block as $record) {
            $statement = $record['source'];
            $line = $record['line'];
            if (preg_match('/^http_response_code\s*\(\s*(\d+)\s*\)$/', $statement, $m)) {
                $ops[] = [
                    'op' => 'WEB_STATUS_CODE',
                    'code' => (int) $m[1],
                    'line' => $line,
                    'source' => $statement,
                ];
                continue;
            }

            if (preg_match('/^echo\s+json_encode\s*\(\s*\[(.*)\]\s*\)$/s', $statement, $m)) {
                $ops[] = [
                    'op' => 'WEB_ECHO_JSON_ARRAY',
                    'items' => self::parseArrayItems($m[1]),
                    'line' => $line,
                    'source' => $statement,
                ];
                continue;
            }

            if ($statement === 'return') {
                $ops[] = [
                    'op' => 'WEB_RETURN',
                    'line' => $line,
                    'source' => $statement,
                ];
                continue;
            }

            throw new WebCompileException("Unsupported Web API block statement", $sourceFile, $line, $statement);
        }

        return $ops;
    }

    /**
     * Splits simple PHP source into statements while preserving:
     *
     *   if (...) {
     *   }
     *
     * as structural statements.
     *
     * @return list<string>
     */
    private static function splitStatements(string $source, int $baseLine = 1): array
    {
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        $source = preg_replace('/^\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*/m', '', $source) ?? $source;

        $statements = [];
        $current = '';
        $line = $baseLine;
        $statementLine = $baseLine;
        $inString = null;
        $length = strlen($source);

        for ($i = 0; $i < $length; $i++) {
            $ch = $source[$i];

            if (trim($current) === '' && !ctype_space($ch)) {
                $statementLine = $line;
            }

            if ($inString !== null) {
                $current .= $ch;

                if ($ch === "\n") {
                    $line++;
                }

                if ($ch === $inString && ($i === 0 || $source[$i - 1] !== '\\')) {
                    $inString = null;
                }

                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $inString = $ch;
                $current .= $ch;
                continue;
            }

            if ($ch === ';') {
                self::pushStatement($statements, $current, $statementLine);
                $current = '';
                continue;
            }

            if ($ch === '{') {
                $current .= $ch;
                self::pushStatement($statements, $current, $statementLine);
                $current = '';
                continue;
            }

            if ($ch === '}') {
                self::pushStatement($statements, $current, $statementLine);
                $current = '';
                $statements[] = [
                    'source' => '}',
                    'line' => $line,
                ];
                continue;
            }

            $current .= $ch;

            if ($ch === "\n") {
                $line++;
            }
        }

        self::pushStatement($statements, $current, $statementLine);

        return $statements;
    }

    /**
     * @param list<string> $statements
     */
    private static function pushStatement(array &$statements, string $statement, int $line): void
    {
        $statement = trim($statement);

        if ($statement !== '') {
            $statements[] = [
                'source' => $statement,
                'line' => $line,
            ];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function parseArrayItems(string $inside): array
    {
        $parts = self::splitCsv($inside);
        $items = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (preg_match('/^[\'"]([^\'"]+)[\'"]\s*=>\s*true$/', $part, $m)) {
                $items[] = [
                    'key' => $m[1],
                    'kind' => 'bool',
                    'value' => true,
                ];
                continue;
            }

            if (preg_match('/^[\'"]([^\'"]+)[\'"]\s*=>\s*false$/', $part, $m)) {
                $items[] = [
                    'key' => $m[1],
                    'kind' => 'bool',
                    'value' => false,
                ];
                continue;
            }

            if (preg_match('/^[\'"]([^\'"]+)[\'"]\s*=>\s*\$(\w+)$/', $part, $m)) {
                $items[] = [
                    'key' => $m[1],
                    'kind' => 'local',
                    'local' => 'LOCAL:' . $m[2],
                ];
                continue;
            }

            if (preg_match('/^[\'"]([^\'"]+)[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]$/', $part, $m)) {
                $items[] = [
                    'key' => $m[1],
                    'kind' => 'string',
                    'value' => $m[2],
                ];
                continue;
            }

            throw new \RuntimeException("Unsupported JSON array item: {$part}");
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private static function splitCsv(string $source): array
    {
        $items = [];
        $current = '';
        $inString = null;
        $length = strlen($source);

        for ($i = 0; $i < $length; $i++) {
            $ch = $source[$i];

            if ($inString !== null) {
                $current .= $ch;

                if ($ch === $inString && ($i === 0 || $source[$i - 1] !== '\\')) {
                    $inString = null;
                }

                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $inString = $ch;
                $current .= $ch;
                continue;
            }

            if ($ch === ',') {
                $items[] = $current;
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        $items[] = $current;

        return $items;
    }

    /**
     * @param array<string,mixed> $plan
     */
    public static function emitPhpEndpoint(array $plan): string
    {
        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
        ];

        // JINX_EMIT_SINGLE_JSON_HEADER
        $lines[] = 'header("Content-Type: application/json");';
        $lines[] = '';

        foreach ($plan['ops'] as $op) {
            self::emitDirectOp($lines, $op, 0);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<string> $lines
     * @param array<string,mixed> $op
     */
    private static function emitDirectOp(array &$lines, array $op, int $indent): void
    {
        $pad = str_repeat('    ', $indent);

        switch ($op['op']) {
            case 'WEB_READ_BODY_JSON':
                $dst = self::localNameToPhpVar((string) $op['dst']);
                $lines[] = $pad . $dst . ' = json_decode(file_get_contents("php://input"), true);';
                return;

            case 'WEB_IF_MISSING_ARRAY_KEY':
                $arrayName = (string) $op['array'];
                $array = self::localNameToPhpVar(str_starts_with($arrayName, 'LOCAL:') ? $arrayName : 'LOCAL:' . $arrayName);
                $key = var_export((string) $op['key'], true);
                $lines[] = $pad . 'if (!isset(' . $array . '[' . $key . '])) {';

                foreach (($op['then'] ?? []) as $thenOp) {
                    self::emitDirectOp($lines, $thenOp, $indent + 1);
                }

                $lines[] = $pad . '}';
                return;

            case 'WEB_ARRAY_GET':
                $dst = self::localNameToPhpVar((string) $op['dst']);
                $array = self::localNameToPhpVar((string) $op['array']);
                $key = var_export((string) $op['key'], true);
                $lines[] = $pad . $dst . ' = ' . $array . '[' . $key . '];';
                return;

            case 'WEB_STATUS_CODE':
                $lines[] = $pad . 'http_response_code(' . (int) $op['code'] . ');';
                return;

            case 'WEB_ECHO_JSON_ARRAY':
                $lines[] = $pad . 'echo json_encode(' . self::emitDirectArrayLiteral($op['items']) . ');';
                return;

            case 'WEB_RETURN':
                $lines[] = $pad . 'return;';
                return;
        }

        throw new \RuntimeException('Cannot emit direct Web op: ' . (string) ($op['op'] ?? 'UNKNOWN'));
    }

    private static function localNameToPhpVar(string $local): string
    {
        $name = preg_replace('/^LOCAL:/', '', $local) ?? $local;
        $name = preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?? $name;

        if ($name === '' || preg_match('/^[0-9]/', $name)) {
            $name = 'v_' . $name;
        }

        return '$' . $name;
    }

    /**
     * @param list<array<string,mixed>> $items
     */
    private static function emitDirectArrayLiteral(array $items): string
    {
        $chunks = [];

        foreach ($items as $item) {
            $key = var_export((string) $item['key'], true);
            $kind = (string) $item['kind'];

            if ($kind === 'bool') {
                $value = ((bool) $item['value']) ? 'true' : 'false';
            } elseif ($kind === 'string') {
                $value = var_export((string) $item['value'], true);
            } elseif ($kind === 'local') {
                $localName = (string) (
                    $item['value']
                    ?? $item['name']
                    ?? $item['local']
                    ?? $item['var']
                    ?? ''
                );

                if ($localName === '') {
                    throw new \RuntimeException('Cannot emit local JSON item without local name: ' . json_encode($item));
                }

                $value = self::localNameToPhpVar(str_starts_with($localName, 'LOCAL:') ? $localName : 'LOCAL:' . $localName);
            } else {
                throw new \RuntimeException('Cannot emit direct JSON array item kind: ' . $kind);
            }

            $chunks[] = $key . ' => ' . $value;
        }

        return '[' . implode(', ', $chunks) . ']';
    }


    private static function emitOp(array &$lines, array $op): void
    {
        switch ($op['op']) {
            case 'WEB_READ_BODY_JSON':
                $lines[] = '$rawBody = file_get_contents("php://input");';
                $lines[] = '$decodedBody = json_decode(is_string($rawBody) ? $rawBody : "", true);';
                $lines[] = 'if (!is_array($decodedBody)) {';
                $lines[] = '    http_response_code(400);';
                $lines[] = '    header("Content-Type: application/json");';
                $lines[] = '    echo json_encode(["ok" => false, "error" => "Invalid JSON"]);';
                $lines[] = '    echo PHP_EOL;';
                $lines[] = '    return;';
                $lines[] = '}';
                $lines[] = '$locals[' . var_export($op['dst'], true) . '] = $decodedBody;';
                break;

            case 'WEB_IF_MISSING_ARRAY_KEY':
                $lines[] = 'if (!array_key_exists(' . var_export($op['key'], true) . ', $locals[' . var_export($op['array'], true) . '])) {';

                foreach ($op['then'] as $thenOp) {
                    $nested = [];
                    self::emitOp($nested, $thenOp);

                    foreach ($nested as $nestedLine) {
                        $lines[] = '    ' . $nestedLine;
                    }
                }

                $lines[] = '}';
                break;

            case 'WEB_STATUS_CODE':
                $lines[] = 'http_response_code(' . (int) $op['code'] . ');';
                break;

            case 'WEB_ARRAY_GET':
                $lines[] = 'if (!array_key_exists(' . var_export($op['key'], true) . ', $locals[' . var_export($op['array'], true) . '])) {';
                $lines[] = '    http_response_code(400);';
                $lines[] = '    header("Content-Type: application/json");';
                $lines[] = '    echo json_encode(["ok" => false, "error" => "Missing field: ' . addslashes($op['key']) . '"]);';
                $lines[] = '    echo PHP_EOL;';
                $lines[] = '    return;';
                $lines[] = '}';
                $lines[] = '$locals[' . var_export($op['dst'], true) . '] = $locals[' . var_export($op['array'], true) . '][' . var_export($op['key'], true) . '];';
                break;

            case 'WEB_ECHO_JSON_ARRAY':
                $lines[] = '$jinxResponse = [];';

                foreach ($op['items'] as $item) {
                    if ($item['kind'] === 'bool') {
                        $lines[] = '$jinxResponse[' . var_export($item['key'], true) . '] = ' . ($item['value'] ? 'true' : 'false') . ';';
                    } elseif ($item['kind'] === 'local') {
                        $lines[] = '$jinxResponse[' . var_export($item['key'], true) . '] = $locals[' . var_export($item['local'], true) . '];';
                    } elseif ($item['kind'] === 'string') {
                        $lines[] = '$jinxResponse[' . var_export($item['key'], true) . '] = ' . var_export($item['value'], true) . ';';
                    } else {
                        throw new \RuntimeException("Cannot emit JSON item kind: {$item['kind']}");
                    }
                }

                $lines[] = 'header("Content-Type: application/json");';
                $lines[] = 'echo json_encode($jinxResponse, JSON_UNESCAPED_SLASHES);';
                $lines[] = 'echo PHP_EOL;';
                break;

            case 'WEB_RETURN':
                $lines[] = 'return;';
                break;

            default:
                throw new \RuntimeException("Cannot emit Web op: {$op['op']}");
        }
    }

    public static function compileFileToEndpoint(string $sourcePath, string $outputPath): void
    {
        if (!is_file($sourcePath)) {
            throw new \RuntimeException("Missing source file: {$sourcePath}");
        }

        $plan = self::compileFileToPlan($sourcePath);
        $php = self::emitPhpEndpoint($plan);

        $dir = dirname($outputPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($outputPath, $php);
    }
}
