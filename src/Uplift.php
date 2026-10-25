<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle;

use Acencyril\UpliftBundle\Composer\ComposerProject;
use Acencyril\UpliftBundle\Plan\Plan;
use Acencyril\UpliftBundle\Plan\Planner;
use Acencyril\UpliftBundle\Runner\Runner;
use Acencyril\UpliftBundle\Source\OutdatedReport;

/** Reads the project and builds its plan; shared by uplift:plan and uplift:apply. */
final class Uplift
{
    public function __construct(
        private readonly Planner $planner,
        private readonly Runner $runner,
        private readonly Policy $policy,
        private readonly string $projectDir,
        private readonly string $composer = 'composer',
        private readonly string $runtimePhp = \PHP_VERSION,
    ) {
    }

    public function policy(): Policy
    {
        return $this->policy;
    }

    public function projectDir(): string
    {
        return $this->projectDir;
    }

    /**
     * @param string|null $outdatedFile a saved `composer outdated --direct --format=json`; null runs it
     * @param bool        $outdated     false skips composer outdated (Packagist alone)
     */
    public function plan(?string $outdatedFile = null, bool $outdated = true): Plan
    {
        $project = new ComposerProject($this->projectDir);
        $report = null;
        $notes = [];
        if (null !== $outdatedFile) {
            if (!is_file($outdatedFile)) {
                throw new \RuntimeException(sprintf('%s does not exist.', $outdatedFile));
            }
            $report = OutdatedReport::fromJson((string) file_get_contents($outdatedFile));
        } elseif ($outdated) {
            $run = $this->runner->run([$this->composer, 'outdated', '--direct', '--format=json', '--no-interaction'], $this->projectDir, 300);
            if ($run->ok()) {
                // composer may print warnings before the JSON
                $start = strpos($run->output, '{');
                $report = OutdatedReport::fromJson(false === $start ? '' : substr($run->output, $start));
            } else {
                $notes[] = 'composer outdated failed: '.$run->tail(3);
            }
        }
        $plan = $this->planner->plan($project, $this->policy, $report, $this->runtimePhp);

        return [] === $notes ? $plan : new Plan($plan->policy, $plan->moves, array_merge($notes, $plan->notes), $plan->php, $plan->phpAfter);
    }

    public function project(): ComposerProject
    {
        return new ComposerProject($this->projectDir);
    }
}
