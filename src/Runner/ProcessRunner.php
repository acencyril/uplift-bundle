<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Runner;

use Symfony\Component\Process\Process;

final class ProcessRunner implements Runner
{
    public function run(array|string $command, string $cwd, int $timeout = 900): RunResult
    {
        $env = self::withoutDotenv($_SERVER + $_ENV);
        $process = \is_string($command) ? Process::fromShellCommandline($command, $cwd, $env) : new Process($command, $cwd, $env);
        $process->setTimeout($timeout);
        try {
            $process->run();
        } catch (\Throwable $e) {
            return new RunResult(1, $process->getOutput().$process->getErrorOutput()."\n".$e->getMessage());
        }

        return new RunResult((int) $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }

    /**
     * The commands get the environment the console was started with, not what the
     * application's .env files added to it. Run from bin/console, Dotenv has put
     * APP_ENV=dev (and the rest) into $_SERVER, which Process passes on; the test command
     * then booted the dev kernel: phpunit sets APP_ENV=test, but the child's Dotenv sees
     * APP_ENV listed in SYMFONY_DOTENV_VARS and loads .env over it. Each command reads the
     * .env files itself.
     *
     * @param array<string, mixed> $vars the current $_SERVER + $_ENV
     *
     * @return array<string, false> the variables to remove (false removes one in Process)
     */
    public static function withoutDotenv(array $vars): array
    {
        $env = [];
        foreach (explode(',', (string) ($vars['SYMFONY_DOTENV_VARS'] ?? '')) as $name) {
            if ('' !== $name) {
                $env[$name] = false;
            }
        }
        foreach (['SYMFONY_DOTENV_VARS', 'SYMFONY_DOTENV_PATH'] as $name) {
            if (isset($vars[$name])) {
                $env[$name] = false;
            }
        }

        return $env;
    }
}
