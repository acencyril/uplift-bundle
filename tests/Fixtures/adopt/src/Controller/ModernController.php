<?php

namespace App\Controller;

use App\Dto\Order;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class ModernController extends AbstractController
{
    public function __construct(private readonly string $currency = 'EUR')
    {
    }

    #[Route('/api/v2/orders', methods: ['POST'])]
    public function create(#[MapRequestPayload] Order $order, #[MapQueryParameter] int $page = 1): JsonResponse
    {
        return new JsonResponse([$order, $page, $this->currency]);
    }
}
