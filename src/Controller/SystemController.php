<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class SystemController extends AbstractController
{
    private const string APP_NAME = 'penny-track';
    private const string FALLBACK_VERSION = '1.3.0';

    #[Route('/api/about', name: 'api_about', methods: ['GET'])]
    public function about(): JsonResponse
    {
        $tag = shell_exec('git describe --tags --abbrev=0');

        if ($tag !== null && $tag !== '') {
            $version = ltrim(trim($tag), 'v');
        } else {
            $version = self::FALLBACK_VERSION;
        }

        return new JsonResponse([
            'name' => self::APP_NAME,
            'version' => $version,
        ]);
    }

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'healthy']);
    }
}
