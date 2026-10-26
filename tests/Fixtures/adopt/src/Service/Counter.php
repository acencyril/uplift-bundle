<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

class Counter
{
    public function __construct(
        protected LoggerInterface $logger,
        private int $count,
        private readonly string $name,
        private ?\DateTimeImmutable $at,
        private $untyped,
        private array $matches,
        private array $items,
    ) {
    }

    public function inc(): void
    {
        $this->count += 1;
        preg_match('/x/', $this->name, $this->matches);
        foreach ($this->items as &$item) {
            $item = trim($item);
        }
    }

    public function at(\DateTimeImmutable $at): static
    {
        $copy = clone $this;
        $copy->at = $at;

        return $copy;
    }
}
