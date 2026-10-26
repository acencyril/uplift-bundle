<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Product
{
    public function __construct(#[ORM\Column] private string $name)
    {
    }
}
