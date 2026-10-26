<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand('app:modern', 'Already uses the attribute')]
class ModernCommand extends Command
{
    protected static $defaultName = 'app:modern';
}

final class NotACommand
{
    protected static $defaultName = 'not-a-command';
}
