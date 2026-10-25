<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Runner;

use Symfony\Component\Process\Process;

final class ProcessRunner implements Runner
{
    public function run(array|string $command, string $cwd, int $timeout = 900): RunResult
    {
        $process = \is_string($command) ? Process::fromShellCommandline($command, $cwd) : new Process($command, $cwd);
        $process->setTimeout($timeout);
        try {
            $process->run();
        } catch (\Throwable $e) {
            return new RunResult(1, $process->getOutput().$process->getErrorOutput()."\n".$e->getMessage());
        }

        return new RunResult((int) $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }
}
