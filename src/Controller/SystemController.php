<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

class SystemController extends AbstractController
{
    private const string APP_NAME = 'penny-track';
    private const string FALLBACK_VERSION = '2.0.0';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    #[Route('/api/about', name: 'api_about', methods: ['GET'])]
    public function about(): JsonResponse
    {
        return new JsonResponse([
            'name' => self::APP_NAME,
            'version' => $this->getVersion(),
        ]);
    }

    /**
     * Liveness — is the process up?
     *
     * Deliberately does NOT touch the database (GUIDING-LIGHT §8.4). A liveness
     * probe that checks its dependencies is an outage amplifier: the database
     * goes briefly unreachable, every replica is judged unhealthy at the same
     * moment, the orchestrator restarts them all, and a transient blip becomes a
     * restart storm that outlives the original problem.
     *
     * The Docker HEALTHCHECK hits this endpoint; so should a Kubernetes
     * `livenessProbe`.
     */
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'healthy']);
    }

    /**
     * Readiness — can this instance actually serve traffic?
     *
     * This one MAY and DOES query the database: that is its whole job. Failing
     * here means "take me out of the pool", not "kill me", which is why it
     * returns 503 while the process stays up.
     *
     * The split, so nobody has to re-derive it:
     *   /api/health  → process up          → never queries the DB  → liveness
     *   /api/ready   → dependencies usable → queries the DB        → readiness
     */
    #[Route('/api/ready', name: 'api_ready', methods: ['GET'])]
    public function ready(): JsonResponse
    {
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
        } catch (Throwable) {
            return new JsonResponse(
                ['status' => 'not ready', 'error' => 'Database connection failed'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return new JsonResponse(['status' => 'ready']);
    }

    /**
     * Determine the application version.
     *
     * Reads the VERSION file written during the Docker build from the
     * APP_VERSION build arg.
     *
     * There used to be a `shell_exec('git describe')` fallback here; it is gone
     * (GUIDING-LIGHT §8.13). Running a shell command in a production request
     * path to read a version string is both a performance problem — a process
     * fork per /api/about call — and a hardening problem, because it makes the
     * endpoint depend on a binary being present in the image. Stamp the version
     * at build time instead; that works locally too:
     *
     *   APP_VERSION=$(git describe --tags --abbrev=0) docker compose build
     */
    private function getVersion(): string
    {
        $versionFile = $this->getParameter('kernel.project_dir').'/VERSION';

        if (is_file($versionFile)) {
            $version = trim((string) file_get_contents($versionFile));
            if ('' !== $version && 'dev' !== $version) {
                return ltrim($version, 'v');
            }
        }

        return self::FALLBACK_VERSION;
    }
}
