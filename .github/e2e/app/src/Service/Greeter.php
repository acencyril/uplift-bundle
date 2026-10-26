<?php

namespace App\Service;

// A promoted property never written again: a readonly-promotion candidate.
final class Greeter
{
    public function __construct(private string $greeting = 'Hello')
    {
    }

    public function greet(string $name): string
    {
        return $this->greeting.', '.$name;
    }
}
