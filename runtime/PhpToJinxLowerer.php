<?php

declare(strict_types=1);

namespace jinx\lowering;

final class PhpToJinxLowerer
{
    public static function lowerFile(string $path): string
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Missing PHP source file: {$path}");
        }

        return self::lower((string) file_get_contents($path));
    }

    public static function lower(string $source): string
    {
        $source = trim($source);

        // JINX_STRIP_COMMENTS_BEFORE_LOWERING
        $source = preg_replace('/\/\*.*?\*\//s', '', $source) ?? $source;
        $source = preg_replace('/^\s*\/\/.*$/m', '', $source) ?? $source;
        $source = preg_replace('/^\s*#.*$/m', '', $source) ?? $source;

        if (str_starts_with($source, '<?php')) {
            $source = trim(substr($source, 5));
        }

        $statements = array_values(array_filter(
            array_map('trim', explode(';', $source)),
            static fn(string $statement): bool => $statement !== ''
        ));

        $jinx = [];

        foreach ($statements as $statement) {
            // JINX_IGNORE_IMPORT_USE_STATEMENTS
            if (preg_match('/^use\s+[A-Za-z0-9_\\\\]+(?:\s+as\s+[A-Za-z0-9_]+)?$/', $statement)) {
                continue;
            }

            if (preg_match('/^declare\s*\(.*\)$/', $statement)) {
                continue;
            }

            if (preg_match('/^(require_once|require|include_once|include)\b/', $statement)) {
                continue;
            }

            if (preg_match('/^declare\s*\(.*\)$/', $statement)) {
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*"([^"]*)"$/', $statement, $m)) {
                $jinx[] = sprintf('assign %s string %s', $m[1], $m[2]);
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*(-?\d+)$/', $statement, $m)) {
                $jinx[] = sprintf('assign %s int %s', $m[1], $m[2]);
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*\$(\w+)\s*\+\s*\$(\w+)$/', $statement, $m)) {
                $jinx[] = sprintf('assign %s add local %s local %s', $m[1], $m[2], $m[3]);
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*\$(\w+)\s*-\s*\$(\w+)$/', $statement, $m)) {
                $jinx[] = sprintf('assign %s sub local %s local %s', $m[1], $m[2], $m[3]);
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*\$(\w+)\s*\*\s*\$(\w+)$/', $statement, $m)) {
                $jinx[] = sprintf('assign %s mul local %s local %s', $m[1], $m[2], $m[3]);
                continue;
            }

            if (preg_match('/^return\s+strlen\s*\(\s*\$(\w+)\s*\)$/', $statement, $m)) {
                $jinx[] = sprintf('return builtin %s local %s', 'strlen', $m[1]);
                continue;
            }

            if (preg_match('/^return\s+\$(\w+)\s*\+\s*\$(\w+)$/', $statement, $m)) {
                $jinx[] = sprintf('return add local %s local %s', $m[1], $m[2]);
                continue;
            }

            if (preg_match('/^return\s+\$(\w+)\s*-\s*\$(\w+)$/', $statement, $m)) {
                $jinx[] = sprintf('return sub local %s local %s', $m[1], $m[2]);
                continue;
            }

            if (preg_match('/^return\s+\$(\w+)\s*\*\s*\$(\w+)$/', $statement, $m)) {
                $jinx[] = sprintf('return mul local %s local %s', $m[1], $m[2]);
                continue;
            }

            throw new \RuntimeException("Unsupported PHP statement: {$statement}");
        }

        return implode(PHP_EOL, $jinx) . PHP_EOL;
    }
}
