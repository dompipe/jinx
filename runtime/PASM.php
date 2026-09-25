<?php

declare(strict_types=1);

namespace jinx\pasm;

/**
 * PASM is the PHP-facing Oracle-ASM replica layer.
 *
 * This is not a stack VM API. It is the command/register surface that JINX
 * lowers into before any C/native/backend execution happens.
 */
final class PASM
{
    /** @var array<string,mixed> */
    public static array $reg = [
        'AH' => null,
        'ECX' => null,
        'RDX' => null,
        'ADX' => null,
        'BDX' => null,
        'CDX' => null,
        'DDX' => null,
        'EDX' => null,
        'QWORD' => null,
        'STRING' => null,
        'ACC' => null,
        'RET' => null,
        'ZF' => 0,
        'CF' => 0,
        'OF' => 0,
    ];

    /** @var array<string,mixed> */
    public static array $locals = [];

    /** @var array<int,string> */
    public static array $chain = [];

    public static mixed $returnValue = null;
    public static ?string $fault = null;


    public static function start(): self
    {
        self::reset();
        return new self();
    }

    public function end(): mixed
    {
        self::trace('END');
        return self::$returnValue;
    }

    public static function reset(): void
    {
        foreach (self::$reg as $name => $_) {
            self::$reg[$name] = in_array($name, ['ZF', 'CF', 'OF'], true) ? 0 : null;
        }

        self::$locals = [];
        self::$chain = [];
        self::$returnValue = null;
        self::$fault = null;
    }

    public function mov(string $dst, mixed $src): self
    {
        self::trace('MOV');

        self::write($dst, self::read($src));

        return $this;
    }

    public function add(string $dst, mixed $left, mixed $right): self
    {
        self::trace('ADD');

        $a = self::read($left);
        $b = self::read($right);

        self::write($dst, $a + $b);
        self::$reg['ZF'] = ((self::read($dst) == 0) ? 1 : 0);

        return $this;
    }

    public function sub(string $dst, mixed $left, mixed $right): self
    {
        self::trace('SUB');

        $a = self::read($left);
        $b = self::read($right);

        self::write($dst, $a - $b);
        self::$reg['ZF'] = ((self::read($dst) == 0) ? 1 : 0);

        return $this;
    }


    public function mul(string $dst, mixed $left, mixed $right): self
    {
        self::trace('MUL');

        $a = self::read($left);
        $b = self::read($right);

        self::write($dst, $a * $b);
        self::$reg['ZF'] = ((self::read($dst) == 0) ? 1 : 0);

        return $this;
    }

    public function cmp(mixed $left, mixed $right): self
    {
        self::trace('CMP');

        $a = self::read($left);
        $b = self::read($right);

        self::$reg['ZF'] = ($a == $b) ? 1 : 0;
        self::$reg['CF'] = ($a < $b) ? 1 : 0;
        self::$reg['OF'] = 0;

        return $this;
    }


    /**
     * JINX extension command.
     *
     * This is not pretending to be a pure Oracle ASM command. It is a JINX
     * bridge command that lets Oracle-style PASM reach generated PHP builtin
     * wrappers/runtime behavior.
     *
     * Example:
     *   JINX_BUILTIN ACC, strlen, [STRING]
     */
    public function jinx_builtin(string $dst, string $name, array $argRegs): self
    {
        self::trace('JINX_BUILTIN');

        $args = [];

        foreach ($argRegs as $reg) {
            $args[] = self::read($reg);
        }

        if ($name === 'strlen') {
            $value = $args[0] ?? '';

            if (!is_string($value)) {
                self::$fault = 'strlen expects string';
                self::write($dst, null);
                return $this;
            }

            self::write($dst, strlen($value));
            return $this;
        }

        self::$fault = "Unsupported JINX builtin {$name}";
        self::write($dst, null);

        return $this;
    }

    public function ret(mixed $src): self
    {
        self::trace('RET');

        self::$returnValue = self::read($src);

        return $this;
    }

    public static function read(mixed $operand): mixed
    {
        if (!is_string($operand)) {
            return $operand;
        }

        $upper = strtoupper($operand);

        if (array_key_exists($upper, self::$reg)) {
            return self::$reg[$upper];
        }

        if (str_starts_with($operand, 'LOCAL:')) {
            $name = substr($operand, strlen('LOCAL:'));

            if (!array_key_exists($name, self::$locals)) {
                self::$fault = "Undefined local {$name}";
                return null;
            }

            return self::$locals[$name];
        }

        return $operand;
    }

    public static function write(string $dst, mixed $value): void
    {
        $upper = strtoupper($dst);

        if (array_key_exists($upper, self::$reg)) {
            self::$reg[$upper] = $value;
            return;
        }

        if (str_starts_with($dst, 'LOCAL:')) {
            $name = substr($dst, strlen('LOCAL:'));
            self::$locals[$name] = $value;
            return;
        }

        self::$fault = "Unknown PASM destination {$dst}";
    }

    private static function trace(string $command): void
    {
        self::$chain[] = $command;
    }
}
