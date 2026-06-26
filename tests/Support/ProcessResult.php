<?php

declare(strict_types=1);

namespace ReactX\Worker\Tests\Support;

final class ProcessResult
{
    public function __construct(
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly int $exitCode,
    ) {
    }

    public function output(): string
    {
        return $this->stdout . $this->stderr;
    }
}
