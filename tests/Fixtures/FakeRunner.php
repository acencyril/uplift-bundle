<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Tests\Fixtures;

use Acencyril\UpliftBundle\Runner\RunResult;
use Acencyril\UpliftBundle\Runner\Runner;

/**
 * Records every command. Answers come from $answers: the first pattern found in the
 * command line wins (e.g. ['composer update' => 0, 'phpunit' => 1]); default exit 0.
 * A composer update that succeeds adds a marker to composer.lock (still valid JSON). $effects run for
 * the commands they match, before the answer (e.g. Rector rewriting a file).
 */
final class FakeRunner implements Runner
{
    /** @var list<string> */
    public array $commands = [];

    /**
     * @param array<string, int|RunResult|list<int>>       $answers a list answers in turn
     * @param array<string, callable(string, string): void> $effects pattern => fn (cwd, command line)
     */
    public function __construct(private array $answers = [], private array $effects = [])
    {
    }

    public function run(array|string $command, string $cwd, int $timeout = 900): RunResult
    {
        $line = \is_string($command) ? $command : implode(' ', $command);
        $this->commands[] = $line;
        foreach ($this->effects as $pattern => $effect) {
            if (str_contains($line, $pattern)) {
                $effect($cwd, $line);
            }
        }
        foreach ($this->answers as $pattern => &$answer) {
            if (str_contains($line, $pattern)) {
                $a = \is_array($answer) ? (\count($answer) > 1 ? array_shift($answer) : $answer[0]) : $answer;
                $result = $a instanceof RunResult ? $a : new RunResult($a, $a ? "failed: $line" : "ok: $line");
                break;
            }
        }
        unset($answer);
        $result ??= new RunResult(0, "ok: $line");
        if ($result->ok() && str_contains($line, ' update') && is_file($cwd.'/composer.lock')) {
            $lock = json_decode((string) file_get_contents($cwd.'/composer.lock'), true);
            if (\is_array($lock)) {
                $lock['_updated'][] = $line;
                file_put_contents($cwd.'/composer.lock', json_encode($lock, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
            }
        }

        return $result;
    }

    public function ran(string $pattern): bool
    {
        foreach ($this->commands as $c) {
            if (str_contains($c, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
