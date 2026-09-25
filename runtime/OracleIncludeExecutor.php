<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Executes the first proven include/require PHP/Zend subset from Oracle records.
 *
 * This family owns literal include/require edges already resolved by
 * OracleProgramCompiler. Included files execute inside the caller local scope,
 * matching PHP's include-scope behavior for this narrow executable subset.
 */
final class OracleIncludeExecutor
{
    /**
     * @param array<string,mixed> $program
     * @return array{kind:string,output:string,return:mixed,executed_ops:int,family:string}
     */
    public static function execute(array $program): array
    {
        $locals = [];
        $output = '';
        $includedOnce = [];
        $executed = self::executeInto($program, $locals, $output, $includedOnce, $return, true);

        return self::result($output, $return ?? null, $executed);
    }

    /**
     * @param array<string,mixed> $program
     * @param array<string,mixed> $locals
     * @param array<string,bool> $includedOnce
     */
    private static function executeInto(array $program, array &$locals, string &$output, array &$includedOnce, mixed &$returnValue, bool $allowTopReturn): int
    {
        $executed = 0;

        foreach ($program['statements'] ?? [] as $statement) {
            if (!is_array($statement)) {
                continue;
            }

            $op = (string) ($statement['op'] ?? 'O_RAW_PHP_STMT');
            $source = (string) ($statement['source'] ?? '');

            switch ($op) {
                case 'O_DECLARE':
                case 'O_BLOCK_CLOSE':
                    break;

                case 'O_INCLUDE':
                case 'O_REQUIRE':
                    $executed += 1 + self::executeLoaderStatement($op, $statement, $locals, $output, $includedOnce);
                    break;

                case 'O_ASSIGN':
                case 'O_COALESCE':
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

                case 'O_RETURN':
                    $returnValue = self::evaluateExpression(self::stripKeywordStatement($source, 'return'), $locals);
                    $executed++;
                    return $executed;

                default:
                    throw new \RuntimeException("Oracle include execution does not support {$op}: {$source}");
            }
        }

        if ($allowTopReturn && !array_key_exists('__jinx_return_seen', $locals)) {
            $returnValue = null;
        }

        return $executed;
    }

    /**
     * @param array<string,mixed> $statement
     * @param array<string,mixed> $locals
     * @param array<string,bool> $includedOnce
     */
    private static function executeLoaderStatement(string $op, array $statement, array &$locals, string &$output, array &$includedOnce): int
    {
        $features = (array) ($statement['features'] ?? []);
        $loader = strtolower((string) ($features['loader'] ?? ($op === 'O_REQUIRE' ? 'require' : 'include')));
        $once = (bool) ($features['once'] ?? false);
        $targetRealpath = isset($features['target_realpath']) ? (string) $features['target_realpath'] : null;

        if ($once && $targetRealpath !== null && isset($includedOnce[$targetRealpath])) {
            return 0;
        }

        if (!isset($statement['included_oracle_program']) || !is_array($statement['included_oracle_program'])) {
            if ($op === 'O_REQUIRE') {
                throw new \RuntimeException('Oracle require could not resolve included program');
            }

            return 0;
        }

        if ($once && $targetRealpath !== null) {
            $includedOnce[$targetRealpath] = true;
        }

        $includedReturn = null;
        return self::executeInto($statement['included_oracle_program'], $locals, $output, $includedOnce, $includedReturn, false);
    }

    /**
     * @return array{kind:string,output:string,return:mixed,executed_ops:int,family:string}
     */
    private static function result(string $output, mixed $return, int $executed): array
    {
        return [
            'kind' => 'JINX_ORACLE_EXECUTION',
            'family' => 'include-require',
            'output' => $output,
            'return' => $return,
            'executed_ops' => $executed,
        ];
    }

    /**
     * @param array<string,mixed> $locals
     */
    private static function executeAssignStatement(string $source, array &$locals): void
    {
        if (!preg_match('/^\$(\w+)\s*=\s*(.+);?$/', $source, $m)) {
            throw new \RuntimeException("Unsupported Oracle include assignment: {$source}");
        }

        $locals[$m[1]] = self::evaluateExpression($m[2], $locals);
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

        foreach ([['??'], ['+'], ['.']] as $operators) {
            $parts = self::splitTopLevelByOperators($expr, $operators);
            if ($parts !== null) {
                [$leftExpr, $operator, $rightExpr] = $parts;

                if ($operator === '??') {
                    try {
                        $left = self::evaluateExpression($leftExpr, $locals);
                        return $left ?? self::evaluateExpression($rightExpr, $locals);
                    } catch (\RuntimeException) {
                        return self::evaluateExpression($rightExpr, $locals);
                    }
                }

                $left = self::evaluateExpression($leftExpr, $locals);
                $right = self::evaluateExpression($rightExpr, $locals);

                return $operator === '+' ? $left + $right : (string) $left . (string) $right;
            }
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

        throw new \RuntimeException("Unsupported Oracle include expression: {$expr}");
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
     * @param list<string> $operators
     * @return array{0:string,1:string,2:string}|null
     */
    private static function splitTopLevelByOperators(string $expr, array $operators): ?array
    {
        usort($operators, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

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

            if ($depth !== 0) {
                continue;
            }

            foreach ($operators as $operator) {
                if (substr($expr, $i, strlen($operator)) === $operator) {
                    return [substr($expr, 0, $i), $operator, substr($expr, $i + strlen($operator))];
                }
            }
        }

        return null;
    }
}
