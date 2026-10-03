<?php

declare(strict_types=1);

namespace jinx\oracle;

/** Executes a proven goto/label PHP subset from Oracle statement records. */
final class OracleGotoExecutor
{
    /**
     * @param array<string,mixed> $program
     * @return array{kind:string,output:string,return:mixed,executed_ops:int,family:string}
     */
    public static function execute(array $program): array
    {
        $statements = array_values(array_filter(
            $program['statements'] ?? [],
            static fn (mixed $statement): bool => is_array($statement)
        ));
        $labels = self::collectLabels($statements);
        $locals = [];
        $output = '';
        $executed = 0;
        $pc = 0;
        $stepBudget = max(32, count($statements) * 8);

        while ($pc < count($statements)) {
            if ($stepBudget-- <= 0) {
                throw new \RuntimeException('Oracle goto execution exceeded step budget');
            }

            $statement = $statements[$pc];
            $op = (string) ($statement['op'] ?? 'O_RAW_PHP_STMT');
            $source = (string) ($statement['source'] ?? '');

            switch ($op) {
                case 'O_DECLARE':
                case 'O_LABEL':
                    $pc++;
                    break;

                case 'O_ASSIGN':
                    self::executeAssignStatement($source, $locals);
                    $executed++;
                    $pc++;
                    break;

                case 'O_GOTO':
                    $label = self::labelName($statement, $source);
                    if (!array_key_exists($label, $labels)) {
                        throw new \RuntimeException("Oracle goto target is missing: {$label}");
                    }
                    $executed++;
                    $pc = $labels[$label] + 1;
                    break;

                case 'O_ECHO':
                    $output .= (string) self::evaluateExpression(self::stripKeywordStatement($source, 'echo'), $locals);
                    $executed++;
                    $pc++;
                    break;

                case 'O_RETURN':
                    $value = self::evaluateExpression(self::stripKeywordStatement($source, 'return'), $locals);
                    $executed++;

                    return self::result($output, $value, $executed);

                default:
                    throw new \RuntimeException("Oracle goto execution does not support {$op}: {$source}");
            }
        }

        return self::result($output, null, $executed);
    }

    /**
     * @param list<array<string,mixed>> $statements
     * @return array<string,int>
     */
    private static function collectLabels(array $statements): array
    {
        $labels = [];
        foreach ($statements as $index => $statement) {
            if (($statement['op'] ?? null) !== 'O_LABEL') {
                continue;
            }

            $source = (string) ($statement['source'] ?? '');
            $label = self::labelName($statement, $source);
            if (array_key_exists($label, $labels)) {
                throw new \RuntimeException("Oracle duplicate label: {$label}");
            }
            $labels[$label] = $index;
        }

        return $labels;
    }

    /** @param array<string,mixed> $statement */
    private static function labelName(array $statement, string $source): string
    {
        $features = $statement['features'] ?? [];
        if (is_array($features) && isset($features['label']) && is_string($features['label'])) {
            return $features['label'];
        }
        if (is_object($features) && isset($features->label) && is_string($features->label)) {
            return $features->label;
        }

        if (preg_match('/^(?:goto\s+)?([A-Za-z_]\w*)\s*:?\s*;?$/i', trim($source), $m)) {
            return $m[1];
        }

        throw new \RuntimeException("Oracle label could not be read: {$source}");
    }

    /**
     * @return array{kind:string,output:string,return:mixed,executed_ops:int,family:string}
     */
    private static function result(string $output, mixed $return, int $executed): array
    {
        return [
            'kind' => 'JINX_ORACLE_EXECUTION',
            'family' => 'goto-labels',
            'output' => $output,
            'return' => $return,
            'executed_ops' => $executed,
        ];
    }

    /** @param array<string,mixed> $locals */
    private static function executeAssignStatement(string $source, array &$locals): void
    {
        if (!preg_match('/^\$(\w+)\s*=\s*(.+);?$/', $source, $m)) {
            throw new \RuntimeException("Unsupported Oracle goto assignment: {$source}");
        }

        $locals[$m[1]] = self::evaluateExpression($m[2], $locals);
    }

    private static function stripKeywordStatement(string $source, string $keyword): string
    {
        $body = preg_replace('/^' . preg_quote($keyword, '/') . '\b/i', '', $source, 1);

        return rtrim(trim((string) $body), ';');
    }

    /** @param array<string,mixed> $locals */
    private static function evaluateExpression(string $expression, array &$locals): mixed
    {
        $expr = trim(rtrim(trim($expression), ';'));

        $concat = self::splitTopLevelBinary($expr, '.');
        if ($concat !== null) {
            [$leftExpr, $rightExpr] = $concat;

            return (string) self::evaluateExpression($leftExpr, $locals)
                . (string) self::evaluateExpression($rightExpr, $locals);
        }

        if (preg_match('/^\'(.*)\'$/s', $expr, $m)) {
            return stripcslashes($m[1]);
        }

        if (preg_match('/^-?\d+$/', $expr)) {
            return (int) $expr;
        }

        if (preg_match('/^\$(\w+)$/', $expr, $m)) {
            if (!array_key_exists($m[1], $locals)) {
                throw new \RuntimeException("Missing Oracle goto local: {$expr}");
            }

            return $locals[$m[1]];
        }

        throw new \RuntimeException("Unsupported Oracle goto expression: {$expr}");
    }

    /** @return array{0:string,1:string}|null */
    private static function splitTopLevelBinary(string $expr, string $operator): ?array
    {
        $quote = null;
        $depth = 0;
        $length = strlen($expr);

        for ($i = $length - 1; $i >= 0; $i--) {
            $char = $expr[$i];
            if ($quote !== null) {
                if ($char === $quote && ($i === 0 || $expr[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '\'' || $char === '"') {
                $quote = $char;
                continue;
            }
            if ($char === ')' || $char === ']') {
                $depth++;
                continue;
            }
            if ($char === '(' || $char === '[') {
                $depth--;
                continue;
            }
            if ($depth === 0 && $char === $operator) {
                return [trim(substr($expr, 0, $i)), trim(substr($expr, $i + 1))];
            }
        }

        return null;
    }
}
