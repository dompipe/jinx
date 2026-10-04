<?php
declare(strict_types=1);

namespace jinx\oracle;

final class OracleNativeExpressionCompiler
{
    private array $tokens;
    private int $position = 0;
    private int $temporary = 0;
    private array $locals = [];
    private array $ops = [];

    public static function compile(string $source): array
    {
        $compiler = new self();
        $compiler->tokens = array_values(array_filter(token_get_all($source, TOKEN_PARSE),
            static fn($token) => !is_array($token) || !in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        return $compiler->program();
    }

    private function peek(): string
    {
        $token = $this->tokens[$this->position] ?? '';
        return is_array($token) ? $token[1] : $token;
    }

    private function expect(string $text): void
    {
        if ($this->peek() !== $text) throw new \RuntimeException('Expected ' . $text . ', got ' . $this->peek());
        $this->position++;
    }

    private function materialize(int|string $value): string
    {
        if (is_string($value)) return $value;
        $dst = 'temp:' . $this->temporary++;
        $this->ops[] = ['op' => 'OMOV_CONST_LOCAL', 'dst' => $dst, 'value' => $value];
        return $dst;
    }

    private function binary(string $operator, int|string $left, int|string $right): string
    {
        $left = $this->materialize($left);
        $right = $this->materialize($right);
        $dst = 'temp:' . $this->temporary++;
        $kind = ['+' => 'ADD', '-' => 'SUB', '*' => 'MUL'][$operator];
        $this->ops[] = ['op' => 'OMOV_' . $kind . '_LOCAL', 'dst' => $dst, 'left' => $left, 'right' => $right];
        return $dst;
    }

    private function variable(): string
    {
        $token = $this->tokens[$this->position++] ?? null;
        if (!is_array($token) || $token[0] !== T_VARIABLE) throw new \RuntimeException('Expected local variable');
        $name = substr($token[1], 1);
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name) || strlen($name) > 255 ||
            in_array($name, ['GLOBALS', 'this', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV'], true))
            throw new \RuntimeException('Unsupported local variable');
        return $name;
    }

    private function expression(int $minimum = 0, int $depth = 0): int|string
    {
        if ($depth > 128) throw new \RuntimeException('Expression nesting limit exceeded');
        $token = $this->tokens[$this->position] ?? '';
        $text = $this->peek();
        if ($text === '+' || $text === '-') {
            $this->position++;
            $operand = $this->expression(30, $depth + 1);
            $left = $text === '+' ? $operand : $this->binary('-', 0, $operand);
        } elseif ($text === '(') {
            $this->position++;
            $left = $this->expression(0, $depth + 1);
            $this->expect(')');
        } elseif (is_array($token) && $token[0] === T_LNUMBER) {
            $this->position++;
            if (!ctype_digit($text) || (string)(int)$text !== $text) throw new \RuntimeException('Only in-range decimal integers are admitted');
            $left = (int)$text;
        } elseif (is_array($token) && $token[0] === T_VARIABLE) {
            $name = $this->variable();
            $left = $this->locals[$name] ?? ('local:' . $name);
        } else {
            throw new \RuntimeException('Unsupported expression: ' . $text);
        }
        $precedence = ['+' => 10, '-' => 10, '*' => 20];
        while (isset($precedence[$this->peek()]) && $precedence[$this->peek()] >= $minimum) {
            $operator = $this->peek();
            $this->position++;
            $right = $this->expression($precedence[$operator] + 1, $depth + 1);
            $left = $this->binary($operator, $left, $right);
        }
        return $left;
    }

    private function program(): array
    {
        if ($this->peek() === 'declare') {
            foreach (['declare', '(', 'strict_types', '=', '1', ')', ';'] as $part) $this->expect($part);
        }
        while ($this->position < count($this->tokens)) {
            if ($this->peek() === 'return') {
                $this->position++;
                $value = $this->expression();
                $this->expect(';');
                if ($this->position !== count($this->tokens)) throw new \RuntimeException('Unreachable statements are not admitted');
                if (is_int($value)) {
                    $this->ops[] = ['op' => 'ORET_CONST', 'value' => $value];
                } else {
                    $last = end($this->ops);
                    // Fuse the final arithmetic operation into the existing return opcode.
                    if ($last && ($last['dst'] ?? null) === $value && isset($last['left'])) {
                        array_pop($this->ops);
                        $this->ops[] = ['op' => 'ORET_' . substr($last['op'], 5), 'left' => $last['left'], 'right' => $last['right']];
                    } else {
                        $zero = $this->materialize(0);
                        $this->ops[] = ['op' => 'ORET_ADD_LOCAL', 'left' => $value, 'right' => $zero];
                    }
                }
                return ['ops' => $this->ops];
            }
            $name = $this->variable();
            $operator = $this->peek();
            if (!in_array($operator, ['=', '+=', '-=', '*='], true)) throw new \RuntimeException('Unsupported assignment: ' . $operator);
            $this->position++;
            $value = $this->expression();
            $this->expect(';');
            if ($operator !== '=') $value = $this->binary($operator[0], $this->locals[$name] ?? ('local:' . $name), $value);
            // Immutable temporary slots preserve copies when a PHP local is reassigned.
            $this->locals[$name] = $this->materialize($value);
        }
        throw new \RuntimeException('Native Oracle program requires return');
    }
}
