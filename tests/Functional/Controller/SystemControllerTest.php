<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Controller\SystemController;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SystemControllerTest extends WebTestCase
{
    public function test_about_reports_name_and_fallback_version(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/about');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('penny-track', $data['name']);
        $this->assertSame('2.0.0', $data['version']);
    }

    public function test_health_does_not_require_authentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/health');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('healthy', $data['status']);
    }

    public function test_ready_reports_ready_when_database_responds(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/ready');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('ready', $data['status']);
    }

    public function test_ready_reports_service_unavailable_when_database_fails(): void
    {
        KernelTestCase::bootKernel();
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => '/proc/definitely-missing-dir/penny.db',
        ]);

        $controller = new SystemController($connection);

        $response = $controller->ready();

        $this->assertSame(503, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('not ready', $data['status']);
        $this->assertSame('Database connection failed', $data['error']);
    }

    public function test_about_prefers_version_file_over_fallback(): void
    {
        $versionFile = \dirname(__DIR__, 3).'/VERSION';
        $backup = is_file($versionFile) ? (string) file_get_contents($versionFile) : null;

        try {
            file_put_contents($versionFile, "v9.9.9\n");

            $client = static::createClient();
            $client->request('GET', '/api/about');

            $this->assertResponseIsSuccessful();
            $data = json_decode($client->getResponse()->getContent(), true);
            $this->assertSame('9.9.9', $data['version']);
        } finally {
            if (null === $backup) {
                @unlink($versionFile);
            } else {
                file_put_contents($versionFile, $backup);
            }
        }
    }

    public function test_about_ignores_dev_version_file(): void
    {
        $versionFile = \dirname(__DIR__, 3).'/VERSION';
        $backup = is_file($versionFile) ? (string) file_get_contents($versionFile) : null;

        try {
            file_put_contents($versionFile, "dev\n");

            $client = static::createClient();
            $client->request('GET', '/api/about');

            $this->assertResponseIsSuccessful();
            $data = json_decode($client->getResponse()->getContent(), true);
            $this->assertSame('2.0.0', $data['version']);
        } finally {
            if (null === $backup) {
                @unlink($versionFile);
            } else {
                file_put_contents($versionFile, $backup);
            }
        }
    }
}
