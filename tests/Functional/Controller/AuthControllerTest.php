<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\ApiKey;
use App\Repository\ApiKeyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AuthControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    #[Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function test_setup_page_renders_when_no_admin_key_exists(): void
    {
        $this->client->request('GET', '/setup');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Welcome to Penny-Track');
    }

    public function test_setup_page_redirects_to_login_when_configured(): void
    {
        $this->seedAdminKey();

        $this->client->request('GET', '/setup');

        $this->assertResponseRedirects('/login');
    }

    public function test_api_setup_creates_admin_key_then_conflicts(): void
    {
        $this->client->request('POST', '/api/auth/setup');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('api_key', $data);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $data['api_key']);

        $this->assertTrue(static::getContainer()->get(ApiKeyRepository::class)->hasAdminKey());

        // The generated key actually authenticates.
        $this->client->request('GET', '/api/receipts', [], [], ['HTTP_X_API_KEY' => $data['api_key']]);
        $this->assertResponseIsSuccessful();

        $this->client->request('POST', '/api/auth/setup');
        $this->assertResponseStatusCodeSame(409);
        $conflict = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Already configured', $conflict['error']);
    }

    public function test_login_page_redirects_to_setup_without_admin_key(): void
    {
        $this->client->request('GET', '/login');

        $this->assertResponseRedirects('/setup');
    }

    public function test_login_page_renders_when_configured(): void
    {
        $this->seedAdminKey();

        $this->client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Enter Your API Key');
    }

    public function test_verify_returns_bad_request_for_invalid_json(): void
    {
        $this->client->request('POST', '/api/auth/verify', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], 'not-json');

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Invalid JSON body', $data['error']);
    }

    public function test_verify_rejects_unknown_key(): void
    {
        $this->seedAdminKey();

        $this->client->request('POST', '/api/auth/verify', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['api_key' => 'nope']));

        $this->assertResponseStatusCodeSame(401);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['valid']);
    }

    private function seedAdminKey(): void
    {
        $apiKey = new ApiKey();
        $apiKey->setKeyHash(password_hash('admin-key', \PASSWORD_BCRYPT));
        $this->em->persist($apiKey);
        $this->em->flush();
    }
}
