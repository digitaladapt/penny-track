<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApiKey;
use App\Repository\ApiKeyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AuthController extends AbstractController
{
    public function __construct(
        private readonly ApiKeyRepository $apiKeyRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/setup', name: 'app_setup', methods: ['GET'])]
    public function setup(): Response
    {
        if ($this->apiKeyRepository->hasAny()) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('auth/setup.html.twig');
    }

    #[Route('/api/auth/setup', name: 'api_auth_setup', methods: ['POST'])]
    public function apiSetup(): JsonResponse
    {
        if ($this->apiKeyRepository->hasAny()) {
            return new JsonResponse(['error' => 'Already configured'], Response::HTTP_CONFLICT);
        }

        $key = bin2hex(random_bytes(32));
        $hash = password_hash($key, PASSWORD_BCRYPT);

        $apiKey = new ApiKey();
        $apiKey->setKeyHash($hash);
        $this->entityManager->persist($apiKey);
        $this->entityManager->flush();

        return new JsonResponse(['api_key' => $key]);
    }

    #[Route('/login', name: 'app_login', methods: ['GET'])]
    public function login(): Response
    {
        if (!$this->apiKeyRepository->hasAny()) {
            return $this->redirectToRoute('app_setup');
        }

        return $this->render('auth/login.html.twig');
    }

    #[Route('/api/auth/verify', name: 'api_auth_verify', methods: ['POST'])]
    public function verify(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $providedKey = $data['api_key'] ?? '';

        $apiKeys = $this->apiKeyRepository->findAll();
        foreach ($apiKeys as $key) {
            if (password_verify($providedKey, $key->getKeyHash())) {
                return new JsonResponse(['valid' => true]);
            }
        }

        return new JsonResponse(['valid' => false], Response::HTTP_UNAUTHORIZED);
    }
}
