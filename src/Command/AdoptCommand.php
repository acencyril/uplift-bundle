<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Command;

use Acencyril\UpliftBundle\Adopt\Adopter;
use Acencyril\UpliftBundle\Adopt\AdoptionPlan;
use Acencyril\UpliftBundle\Adopt\AdoptionResult;
use Acencyril\UpliftBundle\Adopt\Finding;
use Acencyril\UpliftBundle\Adopt\Texts;
use Acencyril\UpliftBundle\Report\ReportWriter;
use Acencyril\UpliftBundle\Uplift;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('uplift:adopt', 'Lists the new features the installed versions bring and where the code could use them; --apply rewrites them with Rector, then the tests decide')]
final class AdoptCommand extends Command
{
    /** @param list<string> $paths */
    public function __construct(
        private readonly Uplift $uplift,
        private readonly Adopter $adopter,
        private readonly string $reportDir,
        private readonly array $paths = ['src'],
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'rewrite with Rector the adoptions the policy allows (UPLIFT_ADOPT=apply), each followed by the tests; put back when they fail')
            ->addOption('path', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'what to scan, relative to the project (default: the adopt_paths setting, src/)')
            ->addOption('report-dir', null, InputOption::VALUE_REQUIRED, 'where report.json and report.md go')
            ->addOption('rehearsal', null, InputOption::VALUE_REQUIRED, 'also add one change per adoption to a Rehearsal changes file')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'language of the titles and explanations: en or fr', 'en')
            ->setHelp(<<<'HELP'
                Each adoption is a new feature of a package version (or of PHP). For the versions this
                site runs, the code is scanned (statically: parsed, never run) for places doing the same
                thing by hand.

                  <info>apply</info>    a Rector rule rewrites them, UPLIFT_ADOPT=apply: done with --apply
                  <info>propose</info>  a person decides (UPLIFT_ADOPT=propose, or an experimental adoption)
                  <info>manual</info>   no automatic rewrite: a person makes the change

                UPLIFT_ADOPT=off|propose|apply (default propose), UPLIFT_ADOPT_SKIP=id1,id2.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $locale = (string) $input->getOption('locale');
        if (!\in_array($locale, Texts::LOCALES, true)) {
            $io->error('--locale is en or fr.');

            return Command::INVALID;
        }
        $project = $this->uplift->project();
        $paths = $input->getOption('path') ?: $this->paths;
        $plan = $this->adopter->find($project, $this->uplift->policy(), array_values($paths), $locale);
        $result = $input->getOption('apply') ? $this->adopter->apply($project, $plan) : null;

        self::show($io, $plan, $result);
        $now = new \DateTimeImmutable();
        $files = ReportWriter::writeAdoptions($input->getOption('report-dir') ?? $this->reportDir, $plan, $result, $now, basename($this->uplift->projectDir()));
        if (null !== ($file = $input->getOption('rehearsal'))) {
            foreach (ReportWriter::adoptionsToRehearsal($file, $plan, $result, $now) as $id) {
                $io->writeln(sprintf('Rehearsal: change "%s" in %s', $id, $file));
            }
        }
        $io->writeln(sprintf('Report: %s, %s', $files['json'], $files['markdown']));

        return Command::SUCCESS;
    }

    /** Shared with uplift:apply --adopt. */
    public static function show(SymfonyStyle $io, AdoptionPlan $plan, ?AdoptionResult $result): void
    {
        $io->section('New features');
        if ([] !== $plan->findings) {
            $io->table(['', 'Adoption', 'Needs', 'Installed', 'Places', 'Rewrite'], array_map(static fn (Finding $f) => [
                ['apply' => '<info>apply</info>', 'propose' => '<comment>propose</comment>', 'manual' => '<comment>manual</comment>'][$f->decision] ?? $f->decision,
                $f->title.' ('.$f->id().')'.($f->adoption->experimental() ? ' [experimental]' : ''),
                $f->requirement(),
                $f->installed ?? '-',
                (string) \count($f->candidates),
                $f->adoption->rector() ? 'Rector' : 'manual',
            ], $plan->findings));
        }
        foreach ($plan->withDecision(...Finding::WITH_CANDIDATES) as $f) {
            $io->writeln(sprintf('<info>%s</info>: %s', $f->id(), $f->reason));
            foreach ($f->candidates as $c) {
                $io->writeln(sprintf('  %s  %s%s', $c->where(), $c->snippet, '' !== $c->hint ? '  -> '.$c->hint : ''));
            }
            if (null !== ($o = $result?->outcomes[$f->id()] ?? null)) {
                $io->writeln('  result: '.$o['status']);
            }
        }
        foreach (array_merge($plan->notes, $result?->notes ?? []) as $note) {
            $io->writeln(' * '.$note);
        }
        $reverted = null === $result ? [] : array_keys(array_filter($result->outcomes, static fn (array $o) => \in_array($o['status'], ['reverted', 'rector_failed'], true)));
        if ([] !== $reverted) {
            $io->warning('Put back: '.implode(', ', $reverted).'. See the report.');
        }
    }
}
