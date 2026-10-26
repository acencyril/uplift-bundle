<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

// Named the Symfony 5 way ($defaultName): an as-command candidate. setName() keeps it
// working on Symfony 7, where the static properties are no longer read.
class LegacyHelloCommand extends Command
{
    protected static $defaultName = 'app:legacy-hello';
    protected static $defaultDescription = 'Says hello, named the old way';

    protected function configure(): void
    {
        $this->setName('app:legacy-hello');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('hello');

        return Command::SUCCESS;
    }
}
