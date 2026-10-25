<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Command;

use Acencyril\UpliftBundle\Apply\Applier;
use Acencyril\UpliftBundle\Composer\ComposerProject;
use Acencyril\UpliftBundle\Report\ReportWriter;
use Acencyril\UpliftBundle\Uplift;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('uplift:apply', 'Does the moves the policy allows, runs Rector and the tests, writes a report. Never pushes, never deploys')]
final class ApplyCommand extends Command
{
    public function __construct(private readonly Uplift $uplift, private readonly Applier $applier, private readonly string $reportDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'create this branch and work on it (e.g. upgrade/2026-11)')
            ->addOption('commit', null, InputOption::VALUE_NONE, 'commit the result (needs a clean working tree)')
            ->addOption('no-rector', null, InputOption::VALUE_NONE, 'do not run Rector, even if the project has it')
            ->addOption('report-dir', null, InputOption::VALUE_REQUIRED, 'where report.json and report.md go')
            ->addOption('rehearsal', null, InputOption::VALUE_REQUIRED, 'also add this run to a Rehearsal changes file (e.g. .rehearsal/changes.json)')
            ->addOption('outdated', null, InputOption::VALUE_REQUIRED, 'a saved "composer outdated --direct --format=json" instead of running it')
            ->addOption('no-outdated', null, InputOption::VALUE_NONE, 'do not run composer outdated (Packagist only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $plan = $this->uplift->plan($input->getOption('outdated'), !$input->getOption('no-outdated'));
        $result = $this->applier->apply(new ComposerProject($this->uplift->projectDir()), $plan, [
            'branch' => $input->getOption('branch'),
            'commit' => (bool) $input->getOption('commit'),
            'rector' => !$input->getOption('no-rector'),
        ]);
        $now = new \DateTimeImmutable();
        $files = ReportWriter::write($input->getOption('report-dir') ?? $this->reportDir, $plan, $result, $now, basename($this->uplift->projectDir()));
        if (null !== ($file = $input->getOption('rehearsal')) && $result->changed()) {
            $id = ReportWriter::toRehearsal($file, $plan, $result, $now);
            $io->writeln(sprintf('Rehearsal: change "%s" in %s', $id, $file));
        }

        $io->writeln(sprintf('Report: %s, %s', $files['json'], $files['markdown']));
        foreach (array_merge($plan->notes, $result->notes) as $note) {
            $io->writeln(' * '.$note);
        }
        $message = match ($result->status) {
            'disabled' => 'UPLIFT_ENABLED is not 1: nothing changed.',
            'nothing_to_do' => 'Nothing the policy allows to move.',
            'passed' => sprintf('%d move(s) applied, tests passed.', \count($result->applied)),
            'untested' => sprintf('%d move(s) applied, no test command.', \count($result->applied)),
            'tests_failed' => sprintf('%d move(s) applied, the tests FAILED.', \count($result->applied)),
            'composer_failed' => 'Composer could not resolve the moves: nothing changed.',
            default => 'Refused: '.implode(' ', $result->notes),
        };
        \in_array($result->status, ['composer_failed', 'tests_failed', 'refused'], true) ? $io->error($message) : $io->success($message);

        return \in_array($result->status, ['composer_failed', 'tests_failed', 'refused'], true) ? Command::FAILURE : Command::SUCCESS;
    }
}
