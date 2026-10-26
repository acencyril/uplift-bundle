<?php

namespace App\Controller;

use App\Service\Greeter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

// Written the Symfony 6.3 way on purpose: Routing\Annotation\Route is deprecated
// (Routing\Attribute\Route since 6.4), for the project's Rector to fix.
final class HomeController extends AbstractController
{
    public function __construct(private Greeter $greeter)
    {
    }

    #[Route('/', name: 'home')]
    public function index(): Response
    {
        return new Response($this->greeter->greet('e2e'));
    }
}
