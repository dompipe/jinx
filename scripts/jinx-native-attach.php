<?php
declare(strict_types=1);

function usage(): void {
    fwrite(STDERR, "Usage: php scripts/jinx-native-attach.php source.php output.c [--jinx-out file]\n");
}

function cIdent(string $name): string {
    $name = preg_replace('/[^A-Za-z0-9_]+/', '_', $name) ?? 'program';
    $name = trim($name, '_');
    if ($name === '' || preg_match('/^[0-9]/', $name)) $name = 'jinx_' . $name;
    return $name;
}

function regId(string $register): string {
    return match ($register) {
        'ecx' => 'JINX_PASM_REG_ECX',
        'ah' => 'JINX_PASM_REG_AH',
        'rdx' => 'JINX_PASM_REG_RDX',
        default => 'JINX_PASM_REG_NONE',
    };
}

function opcode(string $command): string {
    return match ($command) {
        'set' => 'JINX_PASM_OP_SET_I64',
        'add' => 'JINX_PASM_OP_ADD',
        'mul' => 'JINX_PASM_OP_MUL',
        'subea' => 'JINX_PASM_OP_SUB',
        'yield-value' => 'JINX_PASM_OP_YIELD',
        'end' => 'JINX_PASM_OP_END',
        default => throw new RuntimeException('Unsupported native attachment command: ' . $command),
    };
}

function commandToC(array $command): ?string {
    $name = $command['command'] ?? '';
    if ($name === 'set') {
        return '    {' . opcode($name) . ', ' . regId((string)$command['register']) . ', ' . (int)$command['value'] . '},';
    }
    if (in_array($name, ['add', 'mul', 'subea', 'end'], true)) {
        return '    {' . opcode($name) . ', JINX_PASM_REG_NONE, 0},';
    }
    if ($name === 'yield-value') {
        return '    {' . opcode($name) . ', ' . regId((string)$command['from']) . ', 0},';
    }
    if (in_array($name, ['load_str', 'appbuf', 'clbuf'], true)) {
        return null;
    }
    throw new RuntimeException('Unsupported native attachment command: ' . $name);
}

function flattenNativeCommands(array $jinx): array {
    $actions = $jinx['lowering']['actions'] ?? [];
    if ($actions === []) {
        throw new RuntimeException('No native-compatible PASM chain commands found');
    }
    $final = $actions[array_key_last($actions)];
    $commands = [];
    foreach (($final['pasmChain']['commands'] ?? []) as $command) {
        $c = commandToC($command);
        if ($c !== null) $commands[] = $c;
    }
    if ($commands === []) {
        throw new RuntimeException('No native-compatible PASM chain commands found');
    }
    return $commands;
}

function main(array $argv): int {
    if (count($argv) < 3) {
        usage();
        return 1;
    }
    $source = $argv[1];
    $output = $argv[2];
    $jinxOut = null;
    for ($i = 3; $i < count($argv); $i++) {
        if ($argv[$i] === '--jinx-out') {
            $jinxOut = $argv[++$i] ?? throw new RuntimeException('Missing --jinx-out value');
        } else {
            throw new RuntimeException('Unknown option ' . $argv[$i]);
        }
    }
    $root = dirname(__DIR__);
    $tmpJinx = $jinxOut ?: tempnam(sys_get_temp_dir(), 'jinx-native-') . '.json';
    $tmpPasm = tempnam(sys_get_temp_dir(), 'jinx-native-') . '.pasm';
    $cmd = sprintf(
        'php %s %s --jinx-out %s --pasm-out %s',
        escapeshellarg($root . '/scripts/php-to-jinx.php'),
        escapeshellarg($source),
        escapeshellarg($tmpJinx),
        escapeshellarg($tmpPasm)
    );
    exec($cmd . ' 2>&1', $toolOutput, $code);
    if ($code !== 0) {
        throw new RuntimeException("php-to-jinx failed:\n" . implode("\n", $toolOutput));
    }
    $jinx = json_decode(file_get_contents($tmpJinx), true, 512, JSON_THROW_ON_ERROR);
    $commands = flattenNativeCommands($jinx);
    $symbol = cIdent(pathinfo($source, PATHINFO_FILENAME));
    $c = [];
    $c[] = '#include <stdint.h>';
    $c[] = '#include "jinx_pasm_native.h"';
    $c[] = '';
    $c[] = 'static const JinxPasmCommand ' . $symbol . '_commands[] = {';
    array_push($c, ...$commands);
    $c[] = '};';
    $c[] = '';
    $c[] = 'int64_t ' . $symbol . '_run(void) {';
    $c[] = '    JinxPasmFrame frame = {0};';
    $c[] = '    return jinx_pasm_run_struct_chain(' . $symbol . '_commands, sizeof(' . $symbol . '_commands) / sizeof(' . $symbol . '_commands[0]), &frame);';
    $c[] = '}';
    $c[] = '';
    $c[] = '#ifdef JINX_PASM_NATIVE_STANDALONE';
    $c[] = 'int main(void) {';
    $c[] = '    return (int)(' . $symbol . '_run() & 0xff);';
    $c[] = '}';
    $c[] = '#endif';
    $dir = dirname($output);
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    file_put_contents($output, implode("\n", $c) . "\n");
    echo "Wrote {$output} with " . count($commands) . " native struct commands\n";
    return 0;
}

try {
    exit(main($argv));
} catch (Throwable $error) {
    fwrite(STDERR, 'JINX_NATIVE_ATTACH_ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
