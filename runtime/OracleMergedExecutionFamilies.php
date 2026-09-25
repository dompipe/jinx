<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Merged executable Oracle family ledger.
 *
 * This folds generated families into the regular executable family view so
 * suite-level audits and native verification count them as first-class Oracle
 * parity families instead of a side ledger.
 */
final class OracleMergedExecutionFamilies
{
    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        return array_replace(
            OracleExecutionFamilies::all(),
            OracleGeneratedExecutionFamilies::all()
        );
    }

    /** @return array<string,mixed> */
    public static function get(string $family): array
    {
        $families = self::all();
        if (!array_key_exists($family, $families)) {
            throw new \RuntimeException("Unknown merged Oracle execution family: {$family}");
        }
        return $families[$family];
    }
}
