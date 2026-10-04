<?php
declare(strict_types=1);

// Initial native backend for the existing coalesced integer Oracle instruction set.
$root = dirname(__DIR__);
require_once $root . '/runtime/OracleProgramCompiler.php';
use jinx\oracle\OracleProgramCompiler;

try {
    [$script, $input, $output] = $argv + [1 => null, 2 => null];
    if (!$input || !$output) throw new RuntimeException('Usage: php scripts/compile-oracle-native.php input.php output.jxo');
    $source = file_get_contents($input);
    if ($source === false) throw new RuntimeException('Could not read source');
    $tokens = token_get_all($source, TOKEN_PARSE);
    foreach ($tokens as $token) {
        if (is_string($token)) {
            if (!str_contains(';=+-*()', $token)) throw new RuntimeException('Unsupported punctuation');
        } elseif (!in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_VARIABLE, T_LNUMBER, T_RETURN, T_DECLARE, T_STRING], true)) {
            throw new RuntimeException('Unsupported token: ' . token_name($token[0]));
        } elseif ($token[0] === T_STRING && !in_array($token[1], ['strict_types'], true)) {
            throw new RuntimeException('Unsupported identifier: ' . $token[1]);
        } elseif ($token[0] === T_LNUMBER && !ctype_digit($token[1])) {
            throw new RuntimeException('Only decimal integers are admitted');
        } elseif ($token[0] === T_LNUMBER && (string)(int)$token[1] !== $token[1]) {
            throw new RuntimeException('Leading-zero or out-of-range integers are not admitted');
        }
    }
    $compiled = OracleProgramCompiler::compileExecutablePhpFile($input);
    $ops = $compiled['ops'];
    if (!$ops) throw new RuntimeException('No executable Oracle operations');
    $slots = [];
    $defined = [];
    $inputs = [];
    $lines = [];
    $slot = static function (string $name) use (&$slots): int {
        if (!isset($slots[$name])) $slots[$name] = count($slots);
        return $slots[$name];
    };
    $read = static function (string $name) use (&$defined, &$inputs, $slot): int {
        $index = $slot($name);
        if (!isset($defined[$name])) $inputs[$name] = $index;
        return $index;
    };
    $returned = false;
    foreach ($ops as $op) {
        if ($returned) throw new RuntimeException('Unreachable operations are not admitted in this backend');
        switch ($op['op']) {
            case 'OMOV_CONST_LOCAL':
                if (!is_int($op['value'])) throw new RuntimeException('Only integer constants supported');
                $lines[] = 'CONST ' . $slot($op['dst']) . ' ' . $op['value'];
                $defined[$op['dst']] = true;
                break;
            case 'OMOV_ADD_LOCAL':
            case 'OMOV_SUB_LOCAL':
            case 'OMOV_MUL_LOCAL':
                $left = $read($op['left']);
                $right = $read($op['right']);
                $lines[] = substr($op['op'], 5, 3) . ' ' . $slot($op['dst']) . ' ' . $left . ' ' . $right;
                $defined[$op['dst']] = true;
                break;
            case 'ORET_CONST':
                if (!is_int($op['value'])) throw new RuntimeException('Only integer returns supported');
                $lines[] = 'RETURN_CONST ' . $op['value'];
                $returned = true;
                break;
            case 'ORET_ADD_LOCAL':
            case 'ORET_SUB_LOCAL':
            case 'ORET_MUL_LOCAL':
                $lines[] = 'RETURN_' . substr($op['op'], 5, 3) . ' ' . $read($op['left']) . ' ' . $read($op['right']);
                $returned = true;
                break;
            default:
                throw new RuntimeException('Unsupported native Oracle op: ' . $op['op']);
        }
    }
    if (!$returned) throw new RuntimeException('Native Oracle program requires return');
    if (count($slots) > 256 || count($lines) > 4096) throw new RuntimeException('Program limit exceeded');
    $artifact = "JXOR_INT_1\n" . count($slots) . ' ' . count($inputs) . ' ' . count($lines) . "\n";
    foreach ($inputs as $name => $index) $artifact .= 'INPUT ' . $index . ' ' . substr($name, 6) . "\n";
    $artifact .= implode("\n", $lines) . "\n";
    if (file_put_contents($output, $artifact) === false) throw new RuntimeException('Could not write artifact');
    echo 'Compiled executable native Oracle artifact: ' . $output . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Native Oracle compile failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
