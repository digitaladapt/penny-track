<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Command\CreateApiKeyCommand;
use App\Repository\ApiKeyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Override;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CreateApiKeyCommandTest extends KernelTestCase
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

    public function test_creates_an_admin_key_by_default(): void
    {
        $tester = new CommandTester(static::getContainer()->get(CreateApiKeyCommand::class));

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Created ADMIN API key', $display);

        $key = $this->extractKey($display);
        $stored = static::getContainer()->get(ApiKeyRepository::class)->findByKey($key);
        $this->assertNotNull($stored);
        $this->assertFalse($stored->isReadOnly());
    }

    public function test_creates_a_read_only_key_with_the_flag(): void
    {
        $tester = new CommandTester(static::getContainer()->get(CreateApiKeyCommand::class));

        $exitCode = $tester->execute(['--read-only' => true]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Created READ-ONLY API key', $display);

        $key = $this->extractKey($display);
        $stored = static::getContainer()->get(ApiKeyRepository::class)->findByKey($key);
        $this->assertNotNull($stored);
        $this->assertTrue($stored->isReadOnly());
    }

    private function extractKey(string $display): string
    {
        $this->assertMatchesRegularExpression('/[0-9a-f]{64}/', $display);
        preg_match('/([0-9a-f]{64})/', $display, $matches);

        return $matches[1];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
