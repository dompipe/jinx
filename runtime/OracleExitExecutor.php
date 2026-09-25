<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Executes the first proven exit/die PHP/Zend subset from Oracle records.
 *
 * This family owns intentional script termination without terminating the
 * calling test process. Unsupported records throw instead of falling back to
 * PHP.
 */
final class OracleExitExecutor
{
    /**
     * @param array<string,mixed> $program
     * @return array{kind:string,family:string,output:string,return:mixed,executed_ops:int,terminated:bool,exit_code:int}
     */
    public static function execute(array $program): array
    {
        $locals = [];
        $output = '';
        $executed = 0;

        foreach ($program['statements'] ?? [] as $statement) {
            if (!is_array($statement)) {
                continue;
            }

            $op = (string) ($statement['op'] ?? 'O_RAW_PHP_STMT');
            $source = (string) ($statement['source'] ?? '');

            switch ($op) {
                case 'O_DECLARE':
                    break;

                case 'O_ASSIGN':
                    self::executeAssignStatement($source, $locals);
                    $executed++;
                    break;

                case 'O_ECHO':
                    $output .= (string) self::evaluateExpression(self::stripKeywordStatement($source, 'echo'), $locals);
                    $executed++;
                    break;

                case 'O_PRINT':
                    $output .= (string) self::evaluateExpression(self::stripKeywordStatement($source, 'print'), $locals);
                    $executed++;
                    break;

                case 'O_EXIT':
                    [$exitOutput, $exitCode] = self::executeExitStatement($source, $locals);
                    $executed++;

                    return self::result($output . $exitOutput, null, $executed, true, $exitCode);

                case 'O_RETURN':
                    $value = self::evaluateExpression(self::stripKeywordStatement($source, 'return'), $locals);
                    $executed++;

                    return self::result($output, $value, $executed, false, 0);

                default:
                    throw new \RuntimeException("Oracle exit execution does not support {$op}: {$source}");
            }
        }

        return self::result($output, null, $executed, false, 0);
    }

    /**
     * @return array{kind:string,family:string,output:string,return:mixed,executed_ops:int,terminated:bool,exit_code:int}
     */
    private static function result(string $output, mixed $return, int $executed, bool $terminated, int $exitCode): array
    {
        return [
            'kind' => 'JINX_ORACLE_EXECUTION',
            'family' => 'exit-die',
            'output' => $output,
            'return' => $return,
            'executed_ops' => $executed,
            'terminated' => $terminated,
            'exit_code' => $exitCode,
        ];
    }

    /**
     * @param array<string,mixed> $locals
     */
    private static function executeAssignStatement(string $source, array &$locals): void
    {
        if (!preg_match('/^\$(\w+)\s*=\s*(.+);?$/', $source, $m)) {
            throw new \RuntimeException("Unsupported Oracle assignment: {$source}");
        }

        $locals[$m[1]] = self::evaluateExpression($m[2], $locals);
    }

    /**
     * @param array<string,mixed> $locals
     * @return array{0:string,1:int}
     */
    private static function executeExitStatement(string $source, array &$locals): array
    {
        if (!preg_match('/^(exit|die)\s*(?:\((.*)\)|(.+))?\s*;?$/i', trim($source), $m)) {
            throw new \RuntimeException("Unsupported Oracle exit statement: {$source}");
        }

        $argument = trim((string) (($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? '')));

        if ($argument === '') {
            return ['', 0];
        }

        $value = self::evaluateExpression($argument, $locals);

        if (is_int($value)) {
            return ['', $value];
        }

        return [(string) $value, 0];
    }

    private static function stripKeywordStatement(string $source, string $keyword): string
    {
        $body = preg_replace('/^' . preg_quote($keyword, '/') . '\b/i', '', $source, 1);

        return rtrim(trim((string) $body), ';');
    }

    /**
     * @param array<string,mixed> $locals
     */
    private static function evaluateExpression(string $expression, array &$locals): mixed
    {
        $expr = trim(rtrim(trim($expression), ';'));

        if (self::isWrappedInOuterParens($expr)) {
            return self::evaluateExpression(substr($expr, 1, -1), $locals);
        }

        $concat = self::splitTopLevelConcat($expr);
        if ($concat !== null) {
            [$leftExpr, $rightExpr] = $concat;
            return (string) self::evaluateExpression($leftExpr, $locals) . (string) self::evaluateExpression($rightExpr, $locals);
        }

        if (preg_match('/^-?\d+$/', $expr)) {
            return (int) $expr;
        }

        if (preg_match('/^([\'\"])(.*)\1$/', $expr, $m)) {
            return stripcslashes($m[2]);
        }

        if ($expr === 'true') {
            return true;
        }

        if ($expr === 'false') {
            return false;
        }

        if ($expr === 'null') {
            return null;
        }

        if (preg_match('/^\$(\w+)$/', $expr, $m)) {
            if (!array_key_exists($m[1], $locals)) {
                throw new \RuntimeException("Missing Oracle local: {$expr}");
            }

            return $locals[$m[1]];
        }

        throw new \RuntimeException("Unsupported Oracle exit expression: {$expr}");
    }

    private static function isWrappedInOuterParens(string $expr): bool
    {
        if (!str_starts_with($expr, '(') || !str_ends_with($expr, ')')) {
            return false;
        }

        $quote = null;
        $depth = 0;
        $length = strlen($expr);

        for ($i = 0; $i < $length; $i++) {
            $char = $expr[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0 && $i < $length - 1) {
                    return false;
                }
            }
        }

        return $depth === 0;
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private static function splitTopLevelConcat(string $expr): ?array
    {
        $quote = null;
        $depth = 0;
        $length = strlen($expr);

        for ($i = 0; $i < $length; $i++) {
            $char = $expr[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth = max(0, $depth - 1);
                continue;
            }

            if ($depth === 0 && $char === '.') {
                return [substr($expr, 0, $i), substr($expr, $i + 1)];
            }
        }

        return null;
    }
}
