<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Coalesced Oracle/PASM compiler.
 *
 * This does NOT skip the Oracle representation.
 * It lowers .jinx into a smaller Oracle-shaped IR:
 *
 *   assign x int 2
 *   assign y int 3
 *   return add local x local y
 *
 * becomes:
 *
 *   OMOV_CONST_LOCAL LOCAL:x 2
 *   OMOV_CONST_LOCAL LOCAL:y 3
 *   ORET_CONST 5
 *
 * If constants cannot be proven, it keeps an Oracle return op:
 *
 *   ORET_ADD_LOCAL LOCAL:x LOCAL:y
 */
final class CoalescedOracleCompiler
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function compileJinx(string $jinx): array
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $jinx) ?: []),
            static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')
        ));

        $ops = [];
        $knownLocals = [];

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', $line);

            if ($parts === false || $parts === []) {
                continue;
            }

            if ($parts[0] === 'assign') {
                $name = $parts[1] ?? null;
                $type = $parts[2] ?? null;

                if (!is_string($name) || !is_string($type)) {
                    throw new \RuntimeException("Invalid assign line: {$line}");
                }

                $local = 'LOCAL:' . $name;

                if (count($parts) === 4) {
                    [, , , $raw] = $parts;

                    if ($type === 'int') {
                        $value = (int) $raw;
                        $knownLocals[$local] = $value;

                        $ops[] = [
                            'op' => 'OMOV_CONST_LOCAL',
                            'dst' => $local,
                            'value' => $value,
                        ];

                        continue;
                    }

                    if ($type === 'string') {
                        $knownLocals[$local] = $raw;

                        $ops[] = [
                            'op' => 'OMOV_CONST_LOCAL',
                            'dst' => $local,
                            'value' => $raw,
                        ];

                        continue;
                    }

                    if ($type === 'interpolated') {
                        $template = base64_decode($raw, true);
                        if (!is_string($template)) {
                            throw new \RuntimeException("Invalid interpolated assignment payload: {$line}");
                        }

                        $value = self::interpolateString($template, $knownLocals, false);
                        if ($value !== null) {
                            $knownLocals[$local] = $value;
                            $ops[] = [
                                'op' => 'OMOV_CONST_LOCAL',
                                'dst' => $local,
                                'value' => $value,
                            ];
                            continue;
                        }

                        unset($knownLocals[$local]);
                        $ops[] = [
                            'op' => 'OMOV_INTERPOLATED_STRING_LOCAL',
                            'dst' => $local,
                            'template' => $template,
                        ];
                        continue;
                    }
                }

                if (count($parts) === 7 && in_array($type, ['add', 'sub', 'mul'], true)) {
                    [, , $op, $leftKind, $leftName, $rightKind, $rightName] = $parts;

                    if ($leftKind !== 'local' || $rightKind !== 'local') {
                        throw new \RuntimeException("Only local binary assignment operands are supported: {$line}");
                    }

                    $left = 'LOCAL:' . $leftName;
                    $right = 'LOCAL:' . $rightName;

                    if (array_key_exists($left, $knownLocals) && array_key_exists($right, $knownLocals)) {
                        $a = $knownLocals[$left];
                        $b = $knownLocals[$right];

                        $value = match ($op) {
                            'add' => $a + $b,
                            'sub' => $a - $b,
                            'mul' => $a * $b,
                        };

                        $knownLocals[$local] = $value;

                        $ops[] = [
                            'op' => 'OMOV_CONST_LOCAL',
                            'dst' => $local,
                            'value' => $value,
                        ];

                        continue;
                    }

                    unset($knownLocals[$local]);

                    $ops[] = [
                        'op' => 'OMOV_' . strtoupper($op) . '_LOCAL',
                        'dst' => $local,
                        'left' => $left,
                        'right' => $right,
                    ];

                    continue;
                }

                throw new \RuntimeException("Unsupported assign line: {$line}");
            }

            if ($parts[0] === 'return' && ($parts[1] ?? null) === 'builtin') {
                if (count($parts) !== 5) {
                    throw new \RuntimeException("Invalid builtin return line: {$line}");
                }

                [, , $builtin, $argKind, $argName] = $parts;

                if ($builtin !== 'strlen' || $argKind !== 'local') {
                    throw new \RuntimeException("Unsupported builtin return line: {$line}");
                }

                $arg = 'LOCAL:' . $argName;

                if (array_key_exists($arg, $knownLocals) && is_string($knownLocals[$arg])) {
                    $ops[] = [
                        'op' => 'ORET_CONST',
                        'value' => strlen($knownLocals[$arg]),
                    ];
                    continue;
                }

                $ops[] = [
                    'op' => 'ORET_BUILTIN_LOCAL',
                    'builtin' => 'strlen',
                    'arg' => $arg,
                ];

                continue;
            }

            if ($parts[0] === 'return') {
                if (count($parts) !== 6) {
                    throw new \RuntimeException("Invalid return line: {$line}");
                }

                [, $op, $leftKind, $leftName, $rightKind, $rightName] = $parts;

                if ($leftKind !== 'local' || $rightKind !== 'local') {
                    throw new \RuntimeException("Only local return operands are supported: {$line}");
                }

                if (!in_array($op, ['add', 'sub', 'mul'], true)) {
                    throw new \RuntimeException("Unsupported return op: {$op}");
                }

                $left = 'LOCAL:' . $leftName;
                $right = 'LOCAL:' . $rightName;

                if (array_key_exists($left, $knownLocals) && array_key_exists($right, $knownLocals)) {
                    $a = $knownLocals[$left];
                    $b = $knownLocals[$right];

                    $value = match ($op) {
                        'add' => $a + $b,
                        'sub' => $a - $b,
                        'mul' => $a * $b,
                    };

                    $ops[] = [
                        'op' => 'ORET_CONST',
                        'value' => $value,
                    ];

                    continue;
                }

                $ops[] = [
                    'op' => 'ORET_' . strtoupper($op) . '_LOCAL',
                    'left' => $left,
                    'right' => $right,
                ];

                continue;
            }

            throw new \RuntimeException("Unsupported .jinx line: {$line}");
        }

        return self::removeDeadStoresBeforeConstReturn($ops);
    }

    /**
     * If the function ends in ORET_CONST, earlier local stores are dead for this pure subset.
     *
     * @param list<array<string, mixed>> $ops
     * @return list<array<string, mixed>>
     */
    private static function removeDeadStoresBeforeConstReturn(array $ops): array
    {
        if ($ops === []) {
            return $ops;
        }

        $last = $ops[array_key_last($ops)];

        if (($last['op'] ?? null) !== 'ORET_CONST') {
            return $ops;
        }

        return [$last];
    }

    /**
     * @param list<array<string, mixed>> $ops
     * @param array<string, mixed> $runtimeLocals
     */
    public static function execute(array $ops, array $runtimeLocals = []): mixed
    {
        $locals = $runtimeLocals;

        $readLocal = static function (string $name) use (&$locals): mixed {
            if (!array_key_exists($name, $locals)) {
                throw new \RuntimeException("Missing runtime local: {$name}");
            }

            return $locals[$name];
        };

        foreach ($ops as $op) {
            switch ($op['op']) {
                case 'OMOV_CONST_LOCAL':
                    $locals[$op['dst']] = $op['value'];
                    break;

                case 'OMOV_INTERPOLATED_STRING_LOCAL':
                    $locals[$op['dst']] = self::interpolateString((string) $op['template'], $locals, true);
                    break;

                case 'OMOV_ADD_LOCAL':
                    $locals[$op['dst']] = $readLocal($op['left']) + $readLocal($op['right']);
                    break;

                case 'OMOV_SUB_LOCAL':
                    $locals[$op['dst']] = $readLocal($op['left']) - $readLocal($op['right']);
                    break;

                case 'OMOV_MUL_LOCAL':
                    $locals[$op['dst']] = $readLocal($op['left']) * $readLocal($op['right']);
                    break;

                case 'ORET_CONST':
                    return $op['value'];

                case 'ORET_ADD_LOCAL':
                    return $readLocal($op['left']) + $readLocal($op['right']);

                case 'ORET_SUB_LOCAL':
                    return $readLocal($op['left']) - $readLocal($op['right']);

                case 'ORET_MUL_LOCAL':
                    return $readLocal($op['left']) * $readLocal($op['right']);

                case 'ORET_BUILTIN_LOCAL':
                    if ($op['builtin'] === 'strlen') {
                        return strlen((string) $readLocal($op['arg']));
                    }

                    throw new \RuntimeException("Unsupported coalesced builtin: {$op['builtin']}");

                default:
                    throw new \RuntimeException("Unknown coalesced Oracle op: {$op['op']}");
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $locals
     */
    private static function interpolateString(string $template, array $locals, bool $strict): ?string
    {
        $placeholder = "\0JINX_ESCAPED_DOLLAR\0";
        $body = str_replace('\\$', $placeholder, $template);

        $replace = static function (array $m) use ($locals, $strict): string {
            $name = $m[1];
            $local = 'LOCAL:' . $name;
            if (!array_key_exists($local, $locals)) {
                if ($strict) {
                    throw new \RuntimeException("Missing interpolated local: {$name}");
                }
                throw new \UnexpectedValueException('unknown local');
            }
            return (string) $locals[$local];
        };

        try {
            $body = preg_replace_callback('/\{\s*\$(\w+)\s*\}/', $replace, $body) ?? $body;
            $body = preg_replace_callback('/\$(\w+)/', $replace, $body) ?? $body;
        } catch (\UnexpectedValueException) {
            return null;
        }

        $body = str_replace($placeholder, '$', $body);

        return strtr($body, [
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\\\' => '\\',
            '\\"' => '"',
        ]);
    }

    public static function compileJinxToClosure(string $jinx): callable
    {
        $ops = self::compileJinx($jinx);

        return static fn(): mixed => self::execute($ops);
    }
}
