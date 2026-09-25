<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Executable Oracle family ledger.
 *
 * This keeps the transition from "recorded in Oracle" to "executed by Oracle"
 * explicit. A family is listed here only when it has a runtime owner and a test
 * that compares Oracle behavior with PHP behavior.
 */
final class OracleExecutionFamilies
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        return [
            'straight-line' => [
                'state' => 'executable',
                'owner' => OracleStraightLineExecutor::class,
                'test' => 'scripts/test-oracle-straightline-execution.php',
                'ops' => [
                    'O_DECLARE',
                    'O_ASSIGN',
                    'O_DIM_ASSIGN',
                    'O_DIM_FETCH',
                    'O_COALESCE',
                    'O_COMPOUND_ASSIGN',
                    'O_INC',
                    'O_DEC',
                    'O_ECHO',
                    'O_PRINT',
                    'O_RETURN',
                ],
                'builtins' => [
                    'strlen',
                    'strtoupper',
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function get(string $family): array
    {
        $families = self::all();

        if (!array_key_exists($family, $families)) {
            throw new \RuntimeException("Unknown Oracle execution family: {$family}");
        }

        return $families[$family];
    }
}
