<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class SystemController extends AbstractController
{
    private const string APP_NAME = 'penny-track';
    private const string FALLBACK_VERSION = '1.4.0';

    #[Route('/api/about', name: 'api_about', methods: ['GET'])]
    public function about(): JsonResponse
    {
        return new JsonResponse([
            'name' => self::APP_NAME,
            'version' => $this->getVersion(),
        ]);
    }

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'healthy']);
    }

    /**
     * Determine the application version.
     *
     * Tries (in order):
     * 1. VERSION file (written during Docker build via APP_VERSION arg)
     * 2. git describe (works in dev, not in Docker where .git is excluded)
     * 3. Hardcoded fallback
     */
    private function getVersion(): string
    {
        // Try VERSION file (set during Docker build)
        $versionFile = $this->getParameter('kernel.project_dir') . '/VERSION';
        if (file_exists($versionFile)) {
            $version = trim((string) file_get_contents($versionFile));
            if ($version !== '' && $version !== 'dev') {
                return ltrim($version, 'v');
            }
        }

        // Try git (works in dev, not in Docker)
        $version = @shell_exec('git describe --tags --abbrev=0 2>/dev/null');
        if ($version !== null && $version !== '') {
            return ltrim(trim($version), 'v');
        }

        return self::FALLBACK_VERSION;
    }
}
