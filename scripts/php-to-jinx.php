<?php
declare(strict_types=1);

/**
 * Convert regular procedural PHP into a lossless JINX record and, for the first
 * arithmetic subset, PASM register code.
 */

final class JinxTokenStream
{
    /** @var array<int,array<string,mixed>> */
    private array $tokens;
    private int $index = 0;

    /** @param array<int,array<string,mixed>> $tokens */
    public function __construct(array $tokens)
    {
        $this->tokens = array_values(array_filter($tokens, static function (array $token): bool {
            return !in_array($token['name'], ['T_OPEN_TAG', 'T_CLOSE_TAG', 'T_WHITESPACE', 'T_COMMENT', 'T_DOC_COMMENT'], true);
        }));
    }

    public function done(): bool
    {
        return $this->index >= count($this->tokens);
    }

    /** @return array<string,mixed>|null */
    public function peek(int $offset = 0): ?array
    {
        return $this->tokens[$this->index + $offset] ?? null;
    }

    /** @return array<string,mixed> */
    public function take(?string $expected = null): array
    {
        $token = $this->peek();
        if ($token === null) {
            throw new RuntimeException('Unexpected end of PHP input');
        }
        if ($expected !== null && !$this->matches($token, $expected)) {
            throw new RuntimeException("Line {$token['line']}: expected {$expected}, got {$token['text']}");
        }
        $this->index++;
        return $token;
    }

    private function matches(array $token, string $expected): bool
    {
        return $token['name'] === $expected || $token['text'] === $expected;
    }
}

final class JinxProceduralParser
{
    private JinxTokenStream $stream;

    public function __construct(JinxTokenStream $stream)
    {
        $this->stream = $stream;
    }

    /** @return array<int,array<string,mixed>> */
    public function parseProgram(): array
    {
        return $this->parseStatements(null);
    }

    /** @return array<int,array<string,mixed>> */
    private function parseStatements(?string $until): array
    {
        $statements = [];
        while (!$this->stream->done()) {
            $token = $this->stream->peek();
            if ($until !== null && $token !== null && $token['text'] === $until) {
                break;
            }
            $statements[] = $this->parseStatement();
        }
        return $statements;
    }

    /** @return array<string,mixed> */
    private function parseStatement(): array
    {
        $token = $this->stream->peek();
        if ($token === null) {
            throw new RuntimeException('Unexpected end of statement');
        }
        if ($token['name'] === 'T_CLASS') {
            return $this->parseClass();
        }
        if ($token['name'] === 'T_FUNCTION') {
            return $this->parseFunction();
        }
        if ($token['name'] === 'T_ECHO') {
            $this->stream->take('T_ECHO');
            $expr = $this->parseExpression();
            $this->stream->take(';');
            return ['kind' => 'echo', 'expr' => $expr, 'line' => $token['line']];
        }
        if ($token['name'] === 'T_RETURN') {
            $this->stream->take('T_RETURN');
            $expr = $this->parseExpression();
            $this->stream->take(';');
            return ['kind' => 'return', 'expr' => $expr, 'line' => $token['line']];
        }
        if ($token['name'] === 'T_VARIABLE' && ($this->stream->peek(1)['name'] ?? null) === 'T_OBJECT_OPERATOR' && ($this->stream->peek(3)['text'] ?? null) === '=') {
            $object = $this->stream->take('T_VARIABLE');
            $this->stream->take('T_OBJECT_OPERATOR');
            $property = $this->stream->take('T_STRING');
            $this->stream->take('=');
            $expr = $this->parseExpression();
            $this->stream->take(';');
            return ['kind' => 'object-property-assign', 'object' => substr((string)$object['text'], 1), 'property' => $property['text'], 'expr' => $expr, 'line' => $token['line']];
        }
        if ($token['name'] === 'T_VARIABLE' && ($this->stream->peek(1)['text'] ?? null) === '=') {
            $name = $this->stream->take('T_VARIABLE');
            $this->stream->take('=');
            $expr = $this->parseExpression();
            $this->stream->take(';');
            return ['kind' => 'assign', 'target' => substr((string)$name['text'], 1), 'expr' => $expr, 'line' => $token['line']];
        }
        $expr = $this->parseExpression();
        $this->stream->take(';');
        return ['kind' => 'expr', 'expr' => $expr, 'line' => $token['line']];
    }

    /** @return array<string,mixed> */
    private function parseClass(): array
    {
        $start = $this->stream->take('T_CLASS');
        $name = $this->stream->take('T_STRING');
        $extends = null;
        $implements = [];
        if (($this->stream->peek()['name'] ?? null) === 'T_EXTENDS') {
            $this->stream->take('T_EXTENDS');
            $extends = $this->parseQualifiedName();
        }
        if (($this->stream->peek()['name'] ?? null) === 'T_IMPLEMENTS') {
            $this->stream->take('T_IMPLEMENTS');
            while (($this->stream->peek()['text'] ?? null) !== '{') {
                $implements[] = $this->parseQualifiedName();
                if (($this->stream->peek()['text'] ?? null) !== ',') {
                    break;
                }
                $this->stream->take(',');
            }
        }
        $this->stream->take('{');
        $members = [];
        while (($this->stream->peek()['text'] ?? null) !== '}') {
            $members[] = $this->parseClassMember();
        }
        $this->stream->take('}');
        return ['kind' => 'class', 'name' => $name['text'], 'extends' => $extends, 'implements' => $implements, 'members' => $members, 'line' => $start['line']];
    }

    /** @return array<string,mixed> */
    private function parseClassMember(): array
    {
        $modifiers = [];
        while (in_array($this->stream->peek()['name'] ?? null, ['T_PUBLIC', 'T_PROTECTED', 'T_PRIVATE', 'T_STATIC', 'T_FINAL', 'T_ABSTRACT'], true)) {
            $modifiers[] = strtolower(substr((string)$this->stream->take()['name'], 2));
        }
        $token = $this->stream->peek();
        if ($token === null) {
            throw new RuntimeException('Unexpected end of class body');
        }
        if ($token['name'] === 'T_FUNCTION') {
            $method = $this->parseFunction();
            $method['kind'] = 'method';
            $method['modifiers'] = $modifiers;
            return $method;
        }
        if ($token['name'] === 'T_VARIABLE') {
            $name = $this->stream->take('T_VARIABLE');
            $default = null;
            if (($this->stream->peek()['text'] ?? null) === '=') {
                $this->stream->take('=');
                $default = $this->parseExpression();
            }
            $this->stream->take(';');
            return ['kind' => 'property', 'name' => substr((string)$name['text'], 1), 'modifiers' => $modifiers, 'default' => $default, 'line' => $name['line']];
        }
        if ($token['name'] === 'T_CONST') {
            $line = $this->stream->take('T_CONST')['line'];
            $constants = [];
            while (($this->stream->peek()['text'] ?? null) !== ';') {
                $constant = $this->stream->take('T_STRING');
                $this->stream->take('=');
                $constants[] = ['name' => $constant['text'], 'value' => $this->parseExpression()];
                if (($this->stream->peek()['text'] ?? null) !== ',') {
                    break;
                }
                $this->stream->take(',');
            }
            $this->stream->take(';');
            return ['kind' => 'class-constants', 'modifiers' => $modifiers, 'constants' => $constants, 'line' => $line];
        }
        throw new RuntimeException("Line {$token['line']}: unsupported class member {$token['text']}");
    }

    private function parseQualifiedName(): string
    {
        $name = $this->stream->take('T_STRING')['text'];
        while (($this->stream->peek()['text'] ?? null) === '\\') {
            $this->stream->take('\\');
            $name .= '\\' . $this->stream->take('T_STRING')['text'];
        }
        return (string)$name;
    }

    /** @return array<string,mixed> */
    private function parseFunction(): array
    {
        $start = $this->stream->take('T_FUNCTION');
        $name = $this->stream->take('T_STRING');
        $this->stream->take('(');
        $params = [];
        while (($this->stream->peek()['text'] ?? null) !== ')') {
            $param = $this->stream->take('T_VARIABLE');
            $params[] = substr((string)$param['text'], 1);
            if (($this->stream->peek()['text'] ?? null) !== ',') {
                break;
            }
            $this->stream->take(',');
        }
        $this->stream->take(')');
        $this->stream->take('{');
        $body = $this->parseStatements('}');
        $this->stream->take('}');
        return ['kind' => 'function', 'name' => $name['text'], 'params' => $params, 'body' => $body, 'line' => $start['line']];
    }

    /** @return array<string,mixed> */
    private function parseExpression(): array
    {
        $left = $this->parseAdditive();
        while (($this->stream->peek()['text'] ?? null) === '.') {
            $op = $this->stream->take()['text'];
            $left = ['kind' => 'binary', 'op' => $op, 'left' => $left, 'right' => $this->parseAdditive()];
        }
        return $left;
    }

    /** @return array<string,mixed> */
    private function parseAdditive(): array
    {
        $left = $this->parseTerm();
        while (in_array($this->stream->peek()['text'] ?? null, ['+', '-'], true)) {
            $op = $this->stream->take()['text'];
            $left = ['kind' => 'binary', 'op' => $op, 'left' => $left, 'right' => $this->parseTerm()];
        }
        return $left;
    }

    /** @return array<string,mixed> */
    private function parseTerm(): array
    {
        $left = $this->parsePrimary();
        while (in_array($this->stream->peek()['text'] ?? null, ['*', '/'], true)) {
            $op = $this->stream->take()['text'];
            $left = ['kind' => 'binary', 'op' => $op, 'left' => $left, 'right' => $this->parsePrimary()];
        }
        return $left;
    }

    /** @return array<string,mixed> */
    private function parsePrimary(): array
    {
        $token = $this->stream->peek();
        if ($token === null) {
            throw new RuntimeException('Unexpected end of expression');
        }
        if ($token['text'] === '(') {
            $this->stream->take('(');
            $expr = $this->parseExpression();
            $this->stream->take(')');
            return $expr;
        }
        if ($token['name'] === 'T_LNUMBER' || $token['name'] === 'T_DNUMBER') {
            $this->stream->take();
            return ['kind' => 'number', 'value' => $token['name'] === 'T_LNUMBER' ? (int)$token['text'] : (float)$token['text']];
        }
        if ($token['name'] === 'T_CONSTANT_ENCAPSED_STRING') {
            $this->stream->take();
            return ['kind' => 'string', 'value' => stripcslashes(substr((string)$token['text'], 1, -1))];
        }
        if ($token['text'] === '"') {
            return $this->parseInterpolatedString();
        }
        if ($token['name'] === 'T_VARIABLE' && ($this->stream->peek(1)['name'] ?? null) === 'T_OBJECT_OPERATOR') {
            return $this->parseObjectAccess();
        }
        if ($token['name'] === 'T_VARIABLE') {
            $this->stream->take();
            return ['kind' => 'variable', 'name' => substr((string)$token['text'], 1)];
        }
        if ($token['name'] === 'T_NEW') {
            return $this->parseNewExpression();
        }
        if ($token['name'] === 'T_STRING' && ($this->stream->peek(1)['text'] ?? null) === '(') {
            $name = $this->stream->take('T_STRING');
            $this->stream->take('(');
            $args = [];
            while (($this->stream->peek()['text'] ?? null) !== ')') {
                $args[] = $this->parseExpression();
                if (($this->stream->peek()['text'] ?? null) !== ',') {
                    break;
                }
                $this->stream->take(',');
            }
            $this->stream->take(')');
            return ['kind' => 'call', 'name' => $name['text'], 'args' => $args];
        }
        throw new RuntimeException("Line {$token['line']}: unsupported expression token {$token['text']}");
    }

    /** @return array<string,mixed> */
    private function parseObjectAccess(): array
    {
        $object = $this->stream->take('T_VARIABLE');
        $this->stream->take('T_OBJECT_OPERATOR');
        $member = $this->stream->take('T_STRING');
        if (($this->stream->peek()['text'] ?? null) === '(') {
            $this->stream->take('(');
            $args = [];
            while (($this->stream->peek()['text'] ?? null) !== ')') {
                $args[] = $this->parseExpression();
                if (($this->stream->peek()['text'] ?? null) !== ',') {
                    break;
                }
                $this->stream->take(',');
            }
            $this->stream->take(')');
            return ['kind' => 'object-method-call', 'object' => substr((string)$object['text'], 1), 'method' => $member['text'], 'args' => $args, 'line' => $object['line']];
        }
        return ['kind' => 'object-property', 'object' => substr((string)$object['text'], 1), 'property' => $member['text'], 'line' => $object['line']];
    }

    /** @return array<string,mixed> */
    private function parseNewExpression(): array
    {
        $start = $this->stream->take('T_NEW');
        $class = $this->parseQualifiedName();
        $args = [];
        if (($this->stream->peek()['text'] ?? null) === '(') {
            $this->stream->take('(');
            while (($this->stream->peek()['text'] ?? null) !== ')') {
                $args[] = $this->parseExpression();
                if (($this->stream->peek()['text'] ?? null) !== ',') {
                    break;
                }
                $this->stream->take(',');
            }
            $this->stream->take(')');
        }
        return ['kind' => 'new', 'class' => $class, 'args' => $args, 'line' => $start['line']];
    }

    /** @return array<string,mixed> */
    private function parseInterpolatedString(): array
    {
        $start = $this->stream->take('"');
        $parts = [];
        while (($this->stream->peek()['text'] ?? null) !== '"') {
            $token = $this->stream->peek();
            if ($token === null) {
                throw new RuntimeException("Line {$start['line']}: unterminated interpolated string");
            }
            if ($token['name'] === 'T_ENCAPSED_AND_WHITESPACE') {
                $this->stream->take();
                $parts[] = ['kind' => 'literal', 'value' => stripcslashes((string)$token['text'])];
                continue;
            }
            if ($token['name'] === 'T_VARIABLE') {
                $this->stream->take();
                $parts[] = ['kind' => 'variable', 'name' => substr((string)$token['text'], 1)];
                continue;
            }
            throw new RuntimeException("Line {$token['line']}: unsupported interpolated string token {$token['text']}");
        }
        $this->stream->take('"');
        return ['kind' => 'interpolated-string', 'parts' => $parts, 'line' => $start['line']];
    }
}

/** @return array{tokens:array<int,array<string,mixed>>, source:string, diagnostics:array<int,string>} */
function jinxTokenize(string $source): array
{
    $tokens = [];
    $offset = 0;
    $diagnostics = [];
    foreach (token_get_all($source, TOKEN_PARSE) as $token) {
        if (is_array($token)) {
            [$id, $text, $line] = $token;
            $name = token_name($id);
        } else {
            $text = $token;
            $line = substr_count(substr($source, 0, $offset), "\n") + 1;
            $name = $token;
        }
        if (in_array($name, ['T_INTERFACE', 'T_TRAIT', 'T_ENUM'], true)) {
            $diagnostics[] = "Line {$line}: {$text} is reserved for a later JINX class-family phase";
        }
        $tokens[] = ['name' => $name, 'text' => $text, 'line' => $line, 'offset' => $offset, 'length' => strlen($text)];
        $offset += strlen($text);
    }
    return ['tokens' => $tokens, 'source' => implode('', array_column($tokens, 'text')), 'diagnostics' => $diagnostics];
}

function jinxPasmLiteral(mixed $value): string
{
    if (is_string($value)) {
        return json_encode($value, JSON_UNESCAPED_SLASHES);
    }
    return (string)$value;
}

function jinxValueType(mixed $value): string
{
    return is_int($value) ? 'int' : (is_string($value) ? 'string' : get_debug_type($value));
}

/** @return array<string,mixed> */
function jinxMatchValueToRegister(string $register, mixed $value, string $purpose): array
{
    $type = jinxValueType($value);
    $allowed = [
        'ecx' => ['int'],
        'ah' => ['int'],
        'string' => ['string'],
        'buffer' => ['string'],
    ];
    if (!in_array($type, $allowed[$register] ?? [], true)) {
        throw new RuntimeException("Cannot valuation-match {$type} value into PASM {$register} for {$purpose}");
    }
    return ['register' => $register, 'value' => $value, 'type' => $type, 'purpose' => $purpose];
}

/** @param array<int,array<string,mixed>> $matches */
function jinxCommandsForRegisterMatches(array $matches): array
{
    $commands = [];
    foreach ($matches as $match) {
        $register = $match['register'];
        $value = $match['value'];
        if ($register === 'string' || $register === 'buffer') {
            $commands[] = ['type' => 'write-register', 'command' => 'load_str', 'register' => $register, 'value' => (string)$value, 'source' => $match];
        } else {
            $commands[] = ['type' => 'write-register', 'command' => 'set', 'register' => $register, 'value' => $value, 'source' => $match];
        }
    }
    return $commands;
}

/** @param array<int,array<string,mixed>> $commands */
function jinxPasmChain(array $commands, string $purpose, ?array $yield = null): array
{
    $chain = array_values($commands);
    if ($yield !== null) {
        $chain[] = ['type' => 'yield', 'command' => 'yield-value', ...$yield];
    }
    $chain[] = ['type' => 'terminator', 'command' => 'end', 'purpose' => $purpose . '-end'];
    return ['model' => 'pasmFunc1()->pasmFunc2()->...->yieldValue()->end()', 'length' => count($chain), 'yield' => $yield, 'terminator' => 'end', 'commands' => $chain];
}

function jinxPasmChainTerminator(array $chain): array
{
    return $chain['commands'][$chain['length'] - 1];
}

function jinxPasmChainYield(array $chain): array
{
    foreach ($chain['commands'] as $command) {
        if (($command['type'] ?? null) === 'yield') {
            return $command;
        }
    }
    throw new RuntimeException('PASM chain has no yield command');
}

/** @param array<int,array<string,mixed>> $commands */
function jinxEmitCommands(array $commands, array &$lines): void
{
    foreach ($commands as $command) {
        if (($command['type'] ?? null) === 'write-register') {
            $source = $command['source'];
            $lines[] = '; valuation-match ' . jinxPasmLiteral($command['value']) . ' -> ' . $command['register'] . ' (' . $source['purpose'] . ')';
            if ($command['command'] === 'load_str') {
                $lines[] = 'load_str ' . jinxPasmLiteral((string)$command['value']);
            } elseif ($command['command'] === 'set') {
                $lines[] = 'set ' . $command['register'] . ' ' . $command['value'];
            } else {
                throw new RuntimeException('Unsupported write command ' . $command['command']);
            }
            continue;
        }
        if (($command['type'] ?? null) === 'call') {
            $lines[] = $command['command'];
            continue;
        }
        if (($command['type'] ?? null) === 'yield') {
            $lines[] = '; yield ' . $command['from'] . ' as ' . $command['as'] . ' via ' . $command['methodology'];
            continue;
        }
        if (($command['type'] ?? null) === 'terminator' && ($command['command'] ?? null) === 'end') {
            $lines[] = 'end';
            continue;
        }
        throw new RuntimeException('Unsupported compiler command type ' . ($command['type'] ?? '<missing>'));
    }
}

/** @param array<string,mixed> $expr */
function jinxExprValue(array $expr, array $vars): int|string
{
    if ($expr['kind'] === 'number' && is_int($expr['value'])) {
        return $expr['value'];
    }
    if ($expr['kind'] === 'string') {
        return $expr['value'];
    }
    if ($expr['kind'] === 'interpolated-string') {
        $value = '';
        foreach ($expr['parts'] as $part) {
            if ($part['kind'] === 'literal') {
                $value .= $part['value'];
                continue;
            }
            if ($part['kind'] === 'variable' && array_key_exists($part['name'], $vars)) {
                $value .= (string)$vars[$part['name']];
                continue;
            }
            throw new RuntimeException('Unknown interpolated variable $' . ($part['name'] ?? '<unknown>'));
        }
        return $value;
    }
    if ($expr['kind'] === 'variable' && array_key_exists($expr['name'], $vars)) {
        return $vars[$expr['name']];
    }
    throw new RuntimeException('PASM subset currently requires literals or previously assigned variables');
}

/** @param array<int,array<string,mixed>> $statements */
function jinxClassConstructs(array $statements): array
{
    $constructs = [];
    foreach ($statements as $statement) {
        if (($statement['kind'] ?? null) !== 'class') {
            continue;
        }
        $fieldOffset = 1;
        $methodIndex = 0;
        $commands = [
            ['type' => 'class-descriptor', 'command' => 'define-class', 'name' => $statement['name'], 'extends' => $statement['extends'], 'implements' => $statement['implements']],
            ['type' => 'vtable-descriptor', 'command' => 'define-vtable', 'class' => $statement['name'], 'symbol' => 'vtable.' . $statement['name']],
            ['type' => 'layout', 'command' => 'begin-object-layout', 'class' => $statement['name'], 'unit' => 'slot'],
            ['type' => 'hidden-slot', 'command' => 'define-hidden-vptr-slot', 'class' => $statement['name'], 'field' => '__vptr', 'offset' => 0, 'pointsTo' => 'vtable.' . $statement['name']],
        ];
        foreach ($statement['members'] as $member) {
            if ($member['kind'] === 'property') {
                $commands[] = [
                    'type' => 'field-slot',
                    'command' => 'define-field-slot',
                    'class' => $statement['name'],
                    'field' => $member['name'],
                    'offset' => $fieldOffset++,
                    'visibility' => jinxVisibility($member['modifiers']),
                    'static' => in_array('static', $member['modifiers'], true),
                    'default' => $member['default'],
                ];
                continue;
            }
            if ($member['kind'] === 'method') {
                $label = 'class.' . $statement['name'] . '.method.' . $member['name'];
                $commands[] = [
                    'type' => 'method-label',
                    'command' => 'define-method-label',
                    'class' => $statement['name'],
                    'method' => $member['name'],
                    'label' => $label,
                    'params' => $member['params'],
                    'visibility' => jinxVisibility($member['modifiers']),
                    'static' => in_array('static', $member['modifiers'], true),
                    'body' => $member['body'],
                ];
                $commands[] = [
                    'type' => 'dispatch-entry',
                    'command' => 'define-vtable-entry',
                    'class' => $statement['name'],
                    'method' => $member['name'],
                    'slot' => $methodIndex++,
                    'target' => $label,
                ];
                continue;
            }
            if ($member['kind'] === 'class-constants') {
                foreach ($member['constants'] as $constant) {
                    $commands[] = [
                        'type' => 'class-constant',
                        'command' => 'define-class-constant',
                        'class' => $statement['name'],
                        'name' => $constant['name'],
                        'value' => $constant['value'],
                        'visibility' => jinxVisibility($member['modifiers']),
                    ];
                }
            }
        }
        $commands[] = ['type' => 'layout', 'command' => 'end-object-layout', 'class' => $statement['name'], 'fieldSlots' => $fieldOffset - 1, 'totalSlots' => $fieldOffset, 'methodSlots' => $methodIndex];
        $constructs[] = ['class' => $statement['name'], 'commands' => $commands];
    }
    return $constructs;
}

/** @param array<int,array<string,mixed>> $statements */
function jinxObjectConstructions(array $statements): array
{
    $classMethods = jinxClassMethodIndex($statements);
    $constructs = [];
    foreach ($statements as $statement) {
        if (($statement['kind'] ?? null) !== 'assign' || ($statement['expr']['kind'] ?? null) !== 'new') {
            continue;
        }
        $objectName = $statement['target'];
        $className = $statement['expr']['class'];
        $commands = [
            ['type' => 'allocate-object', 'command' => 'allocate-object', 'object' => $objectName, 'class' => $className],
            ['type' => 'write-hidden-slot', 'command' => 'set-vptr', 'object' => $objectName, 'slot' => 0, 'value' => 'vtable.' . $className],
        ];
        if (isset($classMethods[$className]['__construct'])) {
            $commands[] = [
                'type' => 'constructor-call',
                'command' => 'call-constructor',
                'object' => $objectName,
                'this' => $objectName,
                'target' => 'class.' . $className . '.method.__construct',
                'args' => $statement['expr']['args'],
            ];
        }
        $yield = ['from' => $objectName, 'as' => 'object', 'methodology' => 'allocated object pointer with __class and __vptr'];
        $constructs[] = ['object' => $objectName, 'class' => $className, 'pasmChain' => jinxPasmChain($commands, 'construct-' . $objectName, $yield)];
    }
    return $constructs;
}

/** @param array<int,array<string,mixed>> $statements */
function jinxClassPropertyOffsets(array $statements): array
{
    $offsets = [];
    foreach ($statements as $statement) {
        if (($statement['kind'] ?? null) !== 'class') {
            continue;
        }
        $fieldOffset = 1;
        foreach ($statement['members'] as $member) {
            if (($member['kind'] ?? null) === 'property') {
                $offsets[$statement['name']][$member['name']] = $fieldOffset++;
            }
        }
    }
    return $offsets;
}

/**
 * @param array<string,mixed> $expr
 * @param array<string,array<string,int>> $propertyOffsets
 * @return array<int,array<string,mixed>>
 */
function jinxMethodExprCommands(array $expr, string $className, array $propertyOffsets): array
{
    if (($expr['kind'] ?? null) === 'object-property') {
        $property = (string)$expr['property'];
        return [[
            'type' => 'read-property',
            'command' => 'read-property-slot',
            'class' => $className,
            'object' => $expr['object'],
            'property' => $property,
            'slot' => $propertyOffsets[$className][$property] ?? '<dynamic>',
            'target' => 'method-return',
        ]];
    }
    if (($expr['kind'] ?? null) === 'variable') {
        return [[
            'type' => 'read-variable',
            'command' => 'read-local-or-param',
            'name' => $expr['name'],
            'target' => 'method-return',
        ]];
    }
    if (in_array($expr['kind'] ?? null, ['number', 'string'], true)) {
        return [[
            'type' => 'literal-return',
            'command' => 'valuation-match',
            'value' => $expr['value'],
            'target' => 'method-return',
        ]];
    }
    if (($expr['kind'] ?? null) === 'binary') {
        return [
            ...jinxMethodExprCommands($expr['left'], $className, $propertyOffsets),
            ...jinxMethodExprCommands($expr['right'], $className, $propertyOffsets),
            [
                'type' => 'method-operator',
                'command' => 'method-operator',
                'operator' => $expr['op'],
                'target' => 'method-return',
            ],
        ];
    }
    return [[
        'type' => 'dynamic-return',
        'command' => 'evaluate-method-expression',
        'expr' => jinxStaticExprValue($expr),
        'target' => 'method-return',
    ]];
}

/** @param array<int,array<string,mixed>> $statements */
function jinxClassMethodBodies(array $statements): array
{
    $propertyOffsets = jinxClassPropertyOffsets($statements);
    $bodies = [];
    foreach ($statements as $statement) {
        if (($statement['kind'] ?? null) !== 'class') {
            continue;
        }
        foreach ($statement['members'] as $member) {
            if (($member['kind'] ?? null) !== 'method') {
                continue;
            }
            $label = 'class.' . $statement['name'] . '.method.' . $member['name'];
            $commands = [[
                'type' => 'method-entry',
                'command' => 'begin-method',
                'class' => $statement['name'],
                'method' => $member['name'],
                'label' => $label,
            ]];
            if (!in_array('static', $member['modifiers'], true)) {
                $commands[] = ['type' => 'bind-this', 'command' => 'bind-hidden-this', 'class' => $statement['name'], 'target' => 'this'];
            }
            foreach ($member['params'] as $index => $param) {
                $commands[] = ['type' => 'bind-param', 'command' => 'bind-param', 'position' => $index, 'name' => $param, 'target' => 'method-arg-' . $index];
            }
            foreach ($member['body'] as $bodyStatement) {
                if (($bodyStatement['kind'] ?? null) !== 'return') {
                    $commands[] = ['type' => 'method-statement', 'command' => 'retain-method-statement', 'statement' => $bodyStatement['kind'] ?? '<unknown>'];
                    continue;
                }
                $commands = [
                    ...$commands,
                    ...jinxMethodExprCommands($bodyStatement['expr'], $statement['name'], $propertyOffsets),
                    ['type' => 'return', 'command' => 'return-value', 'from' => 'method-return'],
                ];
            }
            $yield = ['from' => 'method-return', 'as' => 'mixed', 'methodology' => 'method body return slot after hidden this/param binding'];
            $bodies[] = [
                'class' => $statement['name'],
                'method' => $member['name'],
                'label' => $label,
                'pasmChain' => jinxPasmChain($commands, 'method-body-' . $statement['name'] . '-' . $member['name'], $yield),
            ];
        }
    }
    return $bodies;
}

/** @param array<int,array<string,mixed>> $statements */
function jinxMethodDispatches(array $statements): array
{
    $objectClasses = [];
    foreach ($statements as $statement) {
        if (($statement['kind'] ?? null) === 'assign' && ($statement['expr']['kind'] ?? null) === 'new') {
            $objectClasses[$statement['target']] = $statement['expr']['class'];
        }
    }
    $dispatches = [];
    foreach ($statements as $statement) {
        $expr = $statement['expr'] ?? null;
        if (!in_array($statement['kind'] ?? null, ['expr', 'echo', 'return'], true) || !is_array($expr) || ($expr['kind'] ?? null) !== 'object-method-call') {
            continue;
        }
        $objectName = $expr['object'];
        $className = $objectClasses[$objectName] ?? '<unknown>';
        $commands = [
            ['type' => 'match-this', 'command' => 'valuation-match-this', 'object' => $objectName, 'class' => $className, 'target' => 'this'],
        ];
        foreach ($expr['args'] as $index => $arg) {
            $commands[] = ['type' => 'match-argument', 'command' => 'valuation-match', 'position' => $index, 'value' => jinxStaticExprValue($arg), 'target' => 'method-arg-' . $index];
        }
        $commands[] = [
            'type' => 'dispatch',
            'command' => 'dispatch-method',
            'object' => $objectName,
            'method' => $expr['method'],
            'vptr' => $objectName . '.__vptr',
            'target' => $className === '<unknown>' ? '<dynamic>' : 'class.' . $className . '.method.' . $expr['method'],
        ];
        $yield = ['from' => 'method-return', 'as' => 'mixed', 'methodology' => 'method dispatch result after hidden this call'];
        $dispatches[] = ['object' => $objectName, 'class' => $className, 'method' => $expr['method'], 'pasmChain' => jinxPasmChain($commands, 'method-call-' . $objectName . '-' . $expr['method'], $yield)];
    }
    return $dispatches;
}

/** @param array<int,array<string,mixed>> $statements */
function jinxObjectInstances(array $statements): array
{
    $classes = [];
    foreach ($statements as $statement) {
        if (($statement['kind'] ?? null) !== 'class') {
            continue;
        }
        $properties = [];
        foreach ($statement['members'] as $member) {
            if (($member['kind'] ?? null) === 'property') {
                $properties[$member['name']] = $member['default'] === null ? null : jinxStaticExprValue($member['default']);
            }
        }
        $classes[$statement['name']] = ['properties' => $properties];
    }

    $objectClasses = [];
    $instances = [];
    foreach ($statements as $statement) {
        if (($statement['kind'] ?? null) === 'assign' && ($statement['expr']['kind'] ?? null) === 'new') {
            $className = $statement['expr']['class'];
            $objectName = $statement['target'];
            $objectClasses[$objectName] = $className;
            $instances[$className] ??= [];
            $instances[$className][] = [$objectName => ['__class' => $className, '__vptr' => 'vtable.' . $className, ...($classes[$className]['properties'] ?? [])]];
            continue;
        }
        if (($statement['kind'] ?? null) === 'object-property-assign') {
            $objectName = $statement['object'];
            $className = $objectClasses[$objectName] ?? null;
            if ($className === null || !isset($instances[$className])) {
                continue;
            }
            foreach ($instances[$className] as &$entry) {
                if (array_key_exists($objectName, $entry)) {
                    $entry[$objectName][$statement['property']] = jinxStaticExprValue($statement['expr']);
                    break;
                }
            }
            unset($entry);
        }
    }
    return $instances;
}

/** @param array<int,array<string,mixed>> $statements */
function jinxClassMethodIndex(array $statements): array
{
    $index = [];
    foreach ($statements as $statement) {
        if (($statement['kind'] ?? null) !== 'class') {
            continue;
        }
        foreach ($statement['members'] as $member) {
            if (($member['kind'] ?? null) === 'method') {
                $index[$statement['name']][$member['name']] = $member;
            }
        }
    }
    return $index;
}

/** @param array<string,mixed> $expr */
function jinxStaticExprValue(array $expr): mixed
{
    return match ($expr['kind'] ?? null) {
        'number', 'string' => $expr['value'],
        'interpolated-string' => implode('', array_map(static function (array $part): string {
            return $part['kind'] === 'literal' ? (string)$part['value'] : '$' . (string)$part['name'];
        }, $expr['parts'])),
        'new' => ['new' => $expr['class'], 'args' => $expr['args']],
        'object-property' => ['object' => $expr['object'], 'property' => $expr['property']],
        'object-method-call' => ['object' => $expr['object'], 'method' => $expr['method'], 'args' => $expr['args']],
        default => $expr,
    };
}

function jinxVisibility(array $modifiers): string
{
    foreach (['public', 'protected', 'private'] as $visibility) {
        if (in_array($visibility, $modifiers, true)) {
            return $visibility;
        }
    }
    return 'public';
}

/** @param array<string,mixed> $expr */
function jinxLowerExprToPasm(array $expr, array &$vars, array &$lines, array &$actions): int|string
{
    if ($expr['kind'] === 'binary') {
        $left = jinxLowerExprToPasm($expr['left'], $vars, $lines, $actions);
        $right = jinxLowerExprToPasm($expr['right'], $vars, $lines, $actions);
        $operator = $expr['op'];
        if ($operator === '.') {
            $result = (string)$left . (string)$right;
            $leftMatch = jinxMatchValueToRegister('string', (string)$left, 'concat-left');
            $rightMatch = jinxMatchValueToRegister('string', (string)$right, 'concat-right');
            $commands = [
                ['type' => 'call', 'command' => 'clbuf', 'purpose' => 'clear-concat-buffer'],
                ...jinxCommandsForRegisterMatches([$leftMatch]),
                ['type' => 'call', 'command' => 'appbuf', 'purpose' => 'append-left'],
                ...jinxCommandsForRegisterMatches([$rightMatch]),
                ['type' => 'call', 'command' => 'appbuf', 'purpose' => 'append-right'],
            ];
            $yield = ['from' => 'buffer', 'as' => 'string', 'methodology' => 'PASM::$buffer after appbuf'];
            $pasmChain = jinxPasmChain($commands, 'operator-.', $yield);
            $lines[] = '; operator . -> PASM::clbuf(), PASM::load_str(), PASM::appbuf()';
            jinxEmitCommands($commands, $lines);
            $lines[] = '; result buffer = ' . jinxPasmLiteral($result);
            $actions[] = ['operator' => '.', 'call' => 'string-concat', 'commands' => $commands, 'pasmChain' => $pasmChain, 'yield' => $yield, 'calls' => ['clbuf', 'load_str', 'appbuf', 'load_str', 'appbuf'], 'matches' => [$leftMatch, $rightMatch], 'left' => (string)$left, 'right' => (string)$right, 'resultRegister' => 'buffer', 'result' => $result];
            return $result;
        }
        if (!is_int($left) || !is_int($right)) {
            throw new RuntimeException('Numeric PASM operator ' . $operator . ' received a string operand');
        }
        $map = [
            '+' => ['call' => 'add', 'result' => 'rdx', 'apply' => static fn (int $a, int $b): int => $a + $b],
            '-' => ['call' => 'subea', 'result' => 'rdx', 'apply' => static fn (int $a, int $b): int => $a - $b],
            '*' => ['call' => 'mul', 'result' => 'ecx', 'apply' => static fn (int $a, int $b): int => $a * $b],
            '/' => ['call' => 'divide', 'result' => 'rdx', 'apply' => static fn (int $a, int $b): int => intdiv($a, $b)],
        ];
        if (!isset($map[$operator])) {
            throw new RuntimeException('Unsupported operator ' . $operator);
        }
        if ($operator === '/' && $right === 0) {
            throw new RuntimeException('Division by zero cannot be lowered');
        }
        $call = $map[$operator]['call'];
        $result = $map[$operator]['apply']($left, $right);
        $leftMatch = jinxMatchValueToRegister('ecx', $left, 'left-operand');
        $rightMatch = jinxMatchValueToRegister('ah', $right, 'right-operand');
        $commands = [
            ...jinxCommandsForRegisterMatches([$leftMatch, $rightMatch]),
            ['type' => 'call', 'command' => $call, 'purpose' => 'operator-' . $operator],
        ];
        $yield = ['from' => $map[$operator]['result'], 'as' => 'int', 'methodology' => 'PASM::$' . $map[$operator]['result'] . ' after ' . $call];
        $pasmChain = jinxPasmChain($commands, 'operator-' . $operator, $yield);
        $lines[] = '; operator ' . $operator . ' -> PASM::' . $call . '()';
        jinxEmitCommands($commands, $lines);
        $lines[] = '; result ' . $map[$operator]['result'] . ' = ' . $result;
        $actions[] = ['operator' => $operator, 'call' => $call, 'commands' => $commands, 'pasmChain' => $pasmChain, 'yield' => $yield, 'matches' => [$leftMatch, $rightMatch], 'left' => $left, 'right' => $right, 'resultRegister' => $map[$operator]['result'], 'result' => $result];
        return $result;
    }
    return jinxExprValue($expr, $vars);
}

/** @param array<int,array<string,mixed>> $statements */
function jinxEmitPasm(array $statements, ?array &$actions = null): string
{
    $vars = [];
    $actions = [];
    $lines = ['; JINX procedural PHP subset lowered to PASM'];
    foreach ($statements as $statement) {
        if ($statement['kind'] === 'assign') {
            $vars[$statement['target']] = jinxLowerExprToPasm($statement['expr'], $vars, $lines, $actions);
            $lines[] = '; $' . $statement['target'] . ' = ' . $vars[$statement['target']];
            continue;
        }
        if ($statement['kind'] === 'return' || $statement['kind'] === 'echo') {
            $value = jinxLowerExprToPasm($statement['expr'], $vars, $lines, $actions);
            if (is_string($value)) {
                $match = jinxMatchValueToRegister('string', $value, $statement['kind'] . '-value');
                $commands = [
                    ...jinxCommandsForRegisterMatches([$match]),
                    ['type' => 'call', 'command' => 'appbuf', 'purpose' => $statement['kind'] . '-string-output'],
                ];
                $yield = ['from' => 'buffer', 'as' => 'string', 'methodology' => 'PASM::$buffer after output appbuf'];
                $pasmChain = jinxPasmChain($commands, $statement['kind'] . '-string-output', $yield);
                $lines[] = '; ' . $statement['kind'] . ' string -> PASM::load_str(), PASM::appbuf()';
                jinxEmitCommands($commands, $lines);
                jinxEmitCommands([jinxPasmChainYield($pasmChain), jinxPasmChainTerminator($pasmChain)], $lines);
                $actions[] = ['operator' => $statement['kind'] . '-string', 'call' => 'string-output', 'commands' => $commands, 'pasmChain' => $pasmChain, 'yield' => $yield, 'calls' => ['load_str', 'appbuf'], 'matches' => [$match], 'value' => $value, 'resultRegister' => 'buffer', 'result' => $value];
                return implode("\n", $lines) . "\n";
            }
            $leftMatch = jinxMatchValueToRegister('ecx', $value, $statement['kind'] . '-value');
            $rightMatch = jinxMatchValueToRegister('ah', 0, 'zero-normalizer');
            $commands = [
                ...jinxCommandsForRegisterMatches([$leftMatch, $rightMatch]),
                ['type' => 'call', 'command' => 'add', 'purpose' => $statement['kind'] . '-numeric-normalize'],
            ];
            $yield = ['from' => 'rdx', 'as' => 'int', 'methodology' => 'PASM::$rdx after add normalizer'];
            $pasmChain = jinxPasmChain($commands, $statement['kind'] . '-numeric-normalize', $yield);
            jinxEmitCommands($commands, $lines);
            jinxEmitCommands([jinxPasmChainYield($pasmChain), jinxPasmChainTerminator($pasmChain)], $lines);
            $actions[] = ['operator' => 'return-normalize', 'call' => 'add', 'commands' => $commands, 'pasmChain' => $pasmChain, 'yield' => $yield, 'matches' => [$leftMatch, $rightMatch], 'left' => $value, 'right' => 0, 'resultRegister' => 'rdx', 'result' => $value];
            return implode("\n", $lines) . "\n";
        }
        if ($statement['kind'] === 'function') {
            $lines[] = '; function ' . $statement['name'] . ' retained in JINX; PASM call lowering is not in this first slice';
            continue;
        }
        throw new RuntimeException('PASM subset does not yet lower statement kind ' . $statement['kind']);
    }
    $lines[] = 'end';
    return implode("\n", $lines) . "\n";
}

function jinxMain(array $argv): int
{
    $sourcePath = $argv[1] ?? null;
    if ($sourcePath === null || in_array($sourcePath, ['-h', '--help'], true)) {
        fwrite(STDERR, "Usage: php scripts/php-to-jinx.php source.php [--jinx-out file] [--pasm-out file] [--reconstruct-out file]\n");
        return $sourcePath === null ? 1 : 0;
    }
    $options = ['jinx-out' => null, 'pasm-out' => null, 'reconstruct-out' => null];
    for ($i = 2; $i < count($argv); $i++) {
        if (str_starts_with($argv[$i], '--')) {
            $key = substr($argv[$i], 2);
            if (!array_key_exists($key, $options)) {
                throw new RuntimeException('Unknown option --' . $key);
            }
            $options[$key] = $argv[++$i] ?? throw new RuntimeException('Missing value for --' . $key);
        }
    }
    $source = file_get_contents($sourcePath);
    if ($source === false) {
        throw new RuntimeException('Unable to read ' . $sourcePath);
    }
    $tokenized = jinxTokenize($source);
    $ast = null;
    if ($tokenized['diagnostics'] === []) {
        $ast = (new JinxProceduralParser(new JinxTokenStream($tokenized['tokens'])))->parseProgram();
    }
    $pasm = null;
    $actions = [];
    if ($options['pasm-out'] !== null && $ast !== null) {
        $pasm = jinxEmitPasm($ast, $actions);
    }
    $classConstructs = $ast === null ? [] : jinxClassConstructs($ast);
    $objectConstructions = $ast === null ? [] : jinxObjectConstructions($ast);
    $objectInstances = $ast === null ? [] : jinxObjectInstances($ast);
    $methodBodies = $ast === null ? [] : jinxClassMethodBodies($ast);
    $methodDispatches = $ast === null ? [] : jinxMethodDispatches($ast);
    $jinx = [
        'jinx' => 'JINX-PHP-PASM/0.1',
        'sourceLanguage' => 'php',
        'phase' => 'regular-php',
        'classPhase' => 'structural-classes-and-created-objects',
        'reconstruction' => ['mode' => 'source-exact', 'sha256' => hash('sha256', $tokenized['source'])],
        'tokens' => $tokenized['tokens'],
        'ast' => $ast,
        'lowering' => ['strategy' => 'split-operators-to-pasm-actions', 'valuation' => 'match-values-to-required-pasm-registers', 'actions' => $actions, 'classConstructs' => $classConstructs, 'objectConstructions' => $objectConstructions, 'objectInstances' => $objectInstances, 'methodBodies' => $methodBodies, 'methodDispatches' => $methodDispatches],
        'diagnostics' => $tokenized['diagnostics'],
    ];
    if ($options['jinx-out'] !== null) {
        file_put_contents($options['jinx-out'], json_encode($jinx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    } else {
        echo json_encode($jinx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
    if ($options['reconstruct-out'] !== null) {
        file_put_contents($options['reconstruct-out'], $tokenized['source']);
    }
    if ($options['pasm-out'] !== null) {
        if ($ast === null) {
            throw new RuntimeException('Cannot emit PASM while JINX has diagnostics');
        }
        file_put_contents($options['pasm-out'], $pasm);
    }
    if ($tokenized['source'] !== $source) {
        throw new RuntimeException('Source reconstruction mismatch');
    }
    if ($tokenized['diagnostics'] !== []) {
        fwrite(STDERR, implode("\n", $tokenized['diagnostics']) . "\n");
        return 2;
    }
    return 0;
}

try {
    exit(jinxMain($argv));
} catch (Throwable $error) {
    fwrite(STDERR, 'JINX_ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
