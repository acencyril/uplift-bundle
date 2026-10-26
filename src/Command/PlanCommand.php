<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Command;

use Acencyril\UpliftBundle\Plan\Move;
use Acencyril\UpliftBundle\Report\ReportWriter;
use Acencyril\UpliftBundle\Uplift;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('uplift:plan', 'Lists what can move (PHP, Symfony, your packages, the others) and what the policy would do')]
final class PlanCommand extends Command
{
    public function __construct(private readonly Uplift $uplift)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'table, json or markdown', 'table')
            ->addOption('outdated', null, InputOption::VALUE_REQUIRED, 'a saved "composer outdated --direct --format=json" instead of running it')
            ->addOption('no-outdated', null, InputOption::VALUE_NONE, 'do not run composer outdated (Packagist only)')
            ->setHelp(<<<'HELP'
                Reads composer.json, composer.lock, <info>composer outdated</info>, Packagist and php.net, then
                classifies every move as patch, minor or major and decides it against the policy
                (UPLIFT_* environment variables):

                  <info>apply</info>    allowed, done by uplift:apply
                  <info>propose</info>  works, but goes past the policy: a person decides
                  <info>blocked</info>  cannot be done here (a PHP above UPLIFT_PHP_MAX, a constraint to widen by hand…)

                Nothing is changed.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $plan = $this->uplift->plan($input->getOption('outdated'), !$input->getOption('no-outdated'));
        $now = new \DateTimeImmutable();

        switch ($input->getOption('format')) {
            case 'json':
                $output->writeln(ReportWriter::encode(ReportWriter::data($plan, null, $now, basename($this->uplift->projectDir()))));

                return Command::SUCCESS;
            case 'markdown':
            case 'md':
                $output->write(ReportWriter::markdown($plan, null, $now, basename($this->uplift->projectDir())));

                return Command::SUCCESS;
            case 'table':
                break;
            default:
                $output->writeln('<error>--format is table, json or markdown.</error>');

                return Command::INVALID;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('Uplift plan');
        if ([] === $plan->moves) {
            $io->success('Everything is up to date.');
        } else {
            $io->table(['', 'Package', 'From', 'To', 'Level', 'Constraint', 'Why'], array_map(static fn (Move $m) => [
                ['apply' => '<info>apply</info>', 'propose' => '<comment>propose</comment>', 'blocked' => '<error>blocked</error>'][$m->decision],
                $m->package.([] !== $m->members ? ' ('.\count($m->members).')' : ''),
                (string) $m->from,
                (string) $m->to,
                $m->level->value,
                $m->rewrites() ? $m->constraintFrom.' -> '.$m->constraintTo : (string) $m->constraintFrom,
                $m->reason,
            ], $plan->moves));
        }
        foreach ($plan->notes as $note) {
            $io->writeln(' * '.$note);
        }

        return Command::SUCCESS;
    }
}
