<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Attribute\Route;

final class ApiController extends AbstractController
{
    public function __construct(private LockFactory $locks)
    {
    }

    #[Route('/api/orders', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        return new JsonResponse($data);
    }

    #[Route('/api/orders')]
    public function list(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $filters = $request->query->all();
        $sort = $request->query->get($request->attributes->get('key'));

        return new JsonResponse([$page, $filters, $sort]);
    }

    #[Route('/api/import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        return new JsonResponse(\count($request->toArray()));
    }

    #[Route('/api/invoices/{id}/pay', methods: ['POST'])]
    public function pay(int $id): JsonResponse
    {
        $lock = $this->locks->createLock('invoice-'.$id);
        $lock->acquire(true);
        try {
            return new JsonResponse(['paid' => $id]);
        } finally {
            $lock->release();
        }
    }

    public function webhook(Request $request, string $body): JsonResponse
    {
        // decodes a string that is not the request: not a candidate
        return new JsonResponse(json_decode($body, true));
    }

    private function decode(Request $request): array
    {
        return $request->toArray();
    }
}
