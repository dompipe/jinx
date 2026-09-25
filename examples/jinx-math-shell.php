<?php
declare(strict_types=1);

final class JinxMathShellError extends RuntimeException {}

/** @param array<string,float> $vars */
function jinx_math_eval_line(string $line, array &$vars): ?string
{
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        return null;
    }
    if (in_array($line, [':q', ':quit', 'quit', 'exit'], true)) {
        return '__QUIT__';
    }
    if ($line === ':help') {
        return 'commands: :help :vars :clear :quit | math: real PHP expressions | assign: x = expression';
    }
    if ($line === ':vars') {
        if ($vars === []) {
            return 'vars: <empty>';
        }
        ksort($vars);
        $pairs = [];
        foreach ($vars as $name => $value) {
            $pairs[] = $name . '=' . jinx_math_format($value);
        }
        return 'vars: ' . implode(', ', $pairs);
    }
    if ($line === ':clear') {
        $vars = [];
        return 'vars: <cleared>';
    }

    if (preg_match('/^(?:let\s+)?\$?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.+)$/', $line, $match)) {
        $value = jinx_math_eval_php_expression($match[2], $vars);
        $vars[$match[1]] = $value;
        return $match[1] . ' = ' . jinx_math_format($value);
    }

    $value = jinx_math_eval_php_expression($line, $vars);
    return jinx_math_format($value);
}

/** @param array<string,float> $vars */
function jinx_math_eval_php_expression(string $expr, array $vars): float
{
    extract($vars, EXTR_SKIP);
    set_error_handler(static function (int $severity, string $message): never {
        throw new JinxMathShellError($message, $severity);
    });
    try {
        $value = eval('return ' . $expr . ';');
    } catch (ParseError $error) {
        throw new JinxMathShellError('PHP parse error: ' . $error->getMessage());
    } catch (Throwable $error) {
        throw new JinxMathShellError($error->getMessage());
    } finally {
        restore_error_handler();
    }
    if (!is_int($value) && !is_float($value)) {
        throw new JinxMathShellError('Expression did not return a number');
    }
    return (float)$value;
}

function jinx_math_format(float $value): string
{
    if (is_infinite($value) || is_nan($value)) {
        return (string)$value;
    }
    if (abs($value - round($value)) < 1e-12) {
        return (string)(int)round($value);
    }
    return rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
}

function jinx_math_shell_main(): int
{
    $vars = [];
    fwrite(STDOUT, "JINX math shell. Type :help or :quit.\n");
    while (($line = fgets(STDIN)) !== false) {
        try {
            $result = jinx_math_eval_line($line, $vars);
            if ($result === null) {
                continue;
            }
            if ($result === '__QUIT__') {
                fwrite(STDOUT, "bye\n");
                return 0;
            }
            fwrite(STDOUT, $result . "\n");
        } catch (Throwable $error) {
            fwrite(STDOUT, "error: " . $error->getMessage() . "\n");
        }
    }
    return 0;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(jinx_math_shell_main());
}
