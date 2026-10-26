<?php

namespace App\Service;

use Symfony\Component\Lock\LockFactory;

// not a controller nor a listener: not a lock candidate
final class Locker
{
    private LockFactory $factory;

    public function run(): void
    {
        $lock = $this->factory->createLock('job');
        $lock->acquire();
        $lock->release();
    }
}
