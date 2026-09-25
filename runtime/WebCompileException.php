<?php

declare(strict_types=1);

namespace jinx\web;

final class WebCompileException extends \RuntimeException
{
    public readonly ?string $sourceFile;
    public readonly ?int $sourceLine;
    public readonly ?string $sourceStatement;

    public function __construct(
        string $message,
        ?string $sourceFile = null,
        ?int $sourceLine = null,
        ?string $sourceStatement = null,
    ) {
        $this->sourceFile = $sourceFile;
        $this->sourceLine = $sourceLine;
        $this->sourceStatement = $sourceStatement;

        $prefix = 'JINX_WEB_COMPILE_ERROR';

        if ($sourceFile !== null) {
            $prefix .= " {$sourceFile}";
        }

        if ($sourceLine !== null) {
            $prefix .= ":{$sourceLine}";
        }

        parent::__construct($prefix . ' - ' . $message);
    }
}
