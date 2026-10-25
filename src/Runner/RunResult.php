<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Runner;

final class RunResult
{
    public function __construct(public readonly int $exitCode, public readonly string $output = '')
    {
    }

    public function ok(): bool
    {
        return 0 === $this->exitCode;
    }

    /** The last lines of the output, enough to see why it failed. */
    public function tail(int $lines = 60): string
    {
        $all = preg_split('/\R/', rtrim($this->output)) ?: [];

        return implode("\n", \array_slice($all, -$lines));
    }
}
