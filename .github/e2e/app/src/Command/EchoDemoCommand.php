<?php

namespace App\Command;

use Acencyril\EchoBundle\EchoEvent;
use Acencyril\EchoBundle\OwnerEcho;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsCommand('app:echo-demo', 'Sends Echo a sign-up (event dispatcher), a page view and a crawler page view')]
final class EchoDemoCommand extends Command
{
    public function __construct(private readonly EventDispatcherInterface $dispatcher, private readonly OwnerEcho $echo)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->dispatcher->dispatch(new EchoEvent('signup', ['email' => 'jane@example.com', 'plan' => 'pro']));
        $visitor = $this->echo->event('page_view', ['user_agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0']);
        $crawler = $this->echo->event('page_view', ['user_agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']);
        $output->writeln(sprintf('signup dispatched; page_view visitor counted: %s; page_view crawler counted: %s', var_export($visitor, true), var_export($crawler, true)));

        return $visitor && !$crawler ? Command::SUCCESS : Command::FAILURE;
    }
}
