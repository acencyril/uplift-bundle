<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Runner;

/** Runs a command in the project: composer, Rector, the tests, git. Faked in tests. */
interface Runner
{
    /** @param list<string>|string $command a list of arguments, or a shell command line */
    public function run(array|string $command, string $cwd, int $timeout = 900): RunResult;
}
