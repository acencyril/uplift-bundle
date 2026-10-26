<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Tests;

use Acencyril\UpliftBundle\Runner\ProcessRunner;
use PHPUnit\Framework\TestCase;

final class ProcessRunnerTest extends TestCase
{
    public function testTheDotenvVariablesAreLeftOut(): void
    {
        self::assertSame(
            ['APP_ENV' => false, 'DATABASE_URL' => false, 'SYMFONY_DOTENV_VARS' => false, 'SYMFONY_DOTENV_PATH' => false],
            ProcessRunner::withoutDotenv(['APP_ENV' => 'dev', 'DATABASE_URL' => 'x', 'PATH' => '/bin', 'UPLIFT_ENABLED' => '1', 'SYMFONY_DOTENV_VARS' => 'APP_ENV,DATABASE_URL', 'SYMFONY_DOTENV_PATH' => '/app/.env']),
        );
        self::assertSame([], ProcessRunner::withoutDotenv(['PATH' => '/bin', 'APP_ENV' => 'prod']), 'without Dotenv, the environment is the real one');
    }

    public function testACommandDoesNotInheritTheConsolesDotenv(): void
    {
        // what bin/console's Dotenv leaves behind: the test command booted the dev kernel
        $saved = [$_SERVER, $_ENV];
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'dev';
        $_SERVER['SYMFONY_DOTENV_VARS'] = $_ENV['SYMFONY_DOTENV_VARS'] = 'APP_ENV';
        $_ENV['UPLIFT_E2E_REAL'] = 'kept';
        try {
            $run = (new ProcessRunner())->run([\PHP_BINARY, '-r', 'echo var_export(getenv("APP_ENV"), true), " ", var_export(getenv("SYMFONY_DOTENV_VARS"), true), " ", getenv("UPLIFT_E2E_REAL");'], sys_get_temp_dir(), 30);
        } finally {
            [$_SERVER, $_ENV] = $saved;
        }

        self::assertSame(0, $run->exitCode, $run->output);
        self::assertSame('false false kept', trim($run->output));
    }
}
