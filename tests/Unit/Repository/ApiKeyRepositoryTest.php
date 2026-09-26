<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Entity\ApiKey;
use App\Repository\ApiKeyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Override;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ApiKeyRepositoryTest extends KernelTestCase
{
    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $em = $this->em();
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function test_has_admin_key_is_false_without_any_key(): void
    {
        $this->assertFalse($this->repository()->hasAdminKey());
    }

    public function test_has_admin_key_is_false_with_only_read_only_keys(): void
    {
        $apiKey = new ApiKey();
        $apiKey->setKeyHash(password_hash('read-only', \PASSWORD_BCRYPT));
        $apiKey->setReadOnly(true);
        $em = $this->em();
        $em->persist($apiKey);
        $em->flush();

        $this->assertFalse($this->repository()->hasAdminKey());
    }

    public function test_has_admin_key_is_true_with_an_admin_key(): void
    {
        $apiKey = new ApiKey();
        $apiKey->setKeyHash(password_hash('admin', \PASSWORD_BCRYPT));
        $em = $this->em();
        $em->persist($apiKey);
        $em->flush();

        $this->assertTrue($this->repository()->hasAdminKey());
    }

    public function test_find_by_key_returns_matching_entity(): void
    {
        $apiKey = new ApiKey();
        $apiKey->setKeyHash(password_hash('secret-key', \PASSWORD_BCRYPT));
        $em = $this->em();
        $em->persist($apiKey);
        $em->flush();

        $found = $this->repository()->findByKey('secret-key');

        $this->assertNotNull($found);
        $this->assertSame($apiKey->getId(), $found->getId());
        $this->assertNull($this->repository()->findByKey('wrong-key'));
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function repository(): ApiKeyRepository
    {
        return static::getContainer()->get(ApiKeyRepository::class);
    }
}
