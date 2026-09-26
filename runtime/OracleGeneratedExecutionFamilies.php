<?php

declare(strict_types=1);

namespace jinx\oracle;

/** Generated executable Oracle family manifest for the pure-builtin batch. */
final class OracleGeneratedExecutionFamilies
{
    public const TOTAL_GENERATED_FAMILIES = 375;

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        $families = [];
        for ($i = 1; $i <= self::TOTAL_GENERATED_FAMILIES; $i++) {
            $family = sprintf('generated-pure-builtin-%03d', $i);
            $families[$family] = [
                'state' => 'executable',
                'owner' => OracleGeneratedBuiltinExecutor::class,
                'test' => 'scripts/test-oracle-generated-175-builtin-execution.php',
                'ops' => ['O_DECLARE', 'O_ASSIGN', 'O_ECHO', 'O_RETURN'],
                'builtins' => self::builtinsForIndex($i),
                'generated_batch' => 'generated-pure-builtin-375',
            ];
        }

        return $families;
    }

    /** @return list<string> */
    private static function builtinsForIndex(int $i): array
    {
        return match ($i % 25) {
            0 => ['strlen'],
            1 => ['strtoupper'],
            2 => ['strtolower'],
            3 => ['trim'],
            4 => ['substr'],
            5 => ['str_replace'],
            6 => ['strrev'],
            7 => ['ucfirst'],
            8 => ['lcfirst'],
            9 => ['str_repeat'],
            10 => ['str_pad'],
            11 => ['abs'],
            12 => ['max'],
            13 => ['min'],
            14 => ['round'],
            15 => ['array_sum'],
            16 => ['array_product'],
            17 => ['count'],
            18 => ['implode'],
            19 => ['json_encode'],
            20 => ['base64_encode'],
            21 => ['md5'],
            22 => ['sha1'],
            23 => ['strlen', 'strtoupper'],
            default => ['substr', 'str_replace'],
        };
    }
}
