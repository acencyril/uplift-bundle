<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LegacyCommand extends Command
{
    protected static $defaultName = 'app:legacy';
    protected static $defaultDescription = 'Does it the old way';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return Command::SUCCESS;
    }
}
