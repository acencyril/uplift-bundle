<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Lock\LockFactory;

final class InvoiceLockSubscriber implements EventSubscriberInterface
{
    public function __construct(private LockFactory $locks)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [RequestEvent::class => 'onRequest'];
    }

    public function onRequest(RequestEvent $event): void
    {
        $lock = $this->locks->createLock('invoice-'.$event->getRequest()->attributes->get('id'));
        if (!$lock->acquire()) {
            $event->getRequest()->attributes->set('_locked', true);
        }
    }
}
