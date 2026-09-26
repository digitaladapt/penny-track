<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Command\ParseJobWorkerCommand;
use App\Entity\ParseJob;
use App\Repository\ParseJobRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Override;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Exercises the long-running worker's single-cycle machinery (spawn, reap,
 * stale detection, exit paths) without ever running a real LLM subprocess:
 * a stub `php` executable is placed first on PATH, and the worker's
 * `new Process(['php', ...])` resolves to it.
 */
class ParseJobWorkerCommandTest extends KernelTestCase
{
    private string $stubDir; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private string|false $originalPath; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private ?string $originalEnvPath; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $em = $this->em();
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->stubDir = sys_get_temp_dir().'/penny-track-php-stub-'.bin2hex(random_bytes(4));
        mkdir($this->stubDir);
        $stub = $this->stubDir.'/php';
        file_put_contents($stub, "#!/bin/sh\nexit \"\${PENNY_FAKE_PHP_EXIT:-0}\"\n");
        chmod($stub, 0755);

        $this->originalPath = getenv('PATH');
        $this->originalEnvPath = $_ENV['PATH'] ?? null;

        $newPath = $this->stubDir.\PATH_SEPARATOR.getenv('PATH');
        putenv('PATH='.$newPath);
        $_ENV['PATH'] = $newPath;
        $_SERVER['PATH'] = $newPath;
    }

    #[Override]
    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
        if (null === $this->originalEnvPath) {
            unset($_ENV['PATH']);
        } else {
            $_ENV['PATH'] = $this->originalEnvPath;
        }
        if (isset($this->originalEnvPath)) {
            $_SERVER['PATH'] = $this->originalEnvPath;
        }

        $this->removeStubDir();

        parent::tearDown();
    }

    public function test_spawns_pending_job_and_exits_after_max_runtime(): void
    {
        $this->setStubExitCode(0);
        $job = $this->persistJob(ParseJob::STATUS_PENDING, 'coffee $5');

        $tester = $this->tester();
        $exitCode = $tester->execute(['--max-runtime' => 1, '--sleep' => 0]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Parse Job Worker', $display);
        $this->assertStringContainsString('Spawning subprocess for job #'.$job->getId(), $display);
        $this->assertStringContainsString('subprocess exited with code 0', $display);
        $this->assertStringContainsString('Worker exiting after max runtime.', $display);

        // The claim moved the job to processing; the (stubbed) subprocess
        // "succeeded", so reaping must NOT reset the job.
        $reloaded = $this->reload($job);
        $this->assertSame(ParseJob::STATUS_PROCESSING, $reloaded->getStatus());
    }

    public function test_nonzero_subprocess_exit_returns_job_to_pending(): void
    {
        $this->setStubExitCode(3);
        $job = $this->persistJob(ParseJob::STATUS_PENDING, 'flaky input', attempts: 0, maxAttempts: 1);

        $tester = $this->tester();
        $exitCode = $tester->execute(['--max-runtime' => 1, '--sleep' => 0]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertStringContainsString('Subprocess exited with code 3', $tester->getDisplay());

        $reloaded = $this->reload($job);
        $this->assertSame(ParseJob::STATUS_PENDING, $reloaded->getStatus());
        $this->assertSame('Subprocess exited with code 3', $reloaded->getLastError());
    }

    public function test_nonzero_subprocess_exit_fails_job_when_attempts_exhausted(): void
    {
        $this->setStubExitCode(1);
        $job = $this->persistJob(ParseJob::STATUS_PENDING, 'doomed', attempts: 0, maxAttempts: 1);

        // Simulate the subprocess having burned its attempt before dying.
        $this->forceAttempts($job, 1);

        $tester = $this->tester();
        $tester->execute(['--max-runtime' => 1, '--sleep' => 0]);

        $reloaded = $this->reload($job);
        $this->assertSame(ParseJob::STATUS_FAILED, $reloaded->getStatus());
        $this->assertSame('Subprocess exited with code 1', $reloaded->getLastError());
    }

    public function test_stale_processing_job_without_subprocess_is_marked_failed(): void
    {
        $this->setStubExitCode(0);
        $job = $this->persistJob(ParseJob::STATUS_PROCESSING, 'orphaned', attempts: 1, maxAttempts: 1);

        // Age the row past the stale threshold (LLM_WORKER_TIMEOUT + 120s).
        $this->ageUpdatedAt($job, new DateTimeImmutable('-1 hour'));

        $tester = $this->tester();
        $exitCode = $tester->execute(['--max-runtime' => 1, '--sleep' => 0]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertStringContainsString('Process died unexpectedly (stale job detected)', $tester->getDisplay());

        $reloaded = $this->reload($job);
        $this->assertSame(ParseJob::STATUS_FAILED, $reloaded->getStatus());
        $this->assertSame('Process died unexpectedly (stale job detected)', $reloaded->getLastError());
    }

    public function test_fresh_processing_job_is_not_reaped_as_stale(): void
    {
        $this->setStubExitCode(0);
        $job = $this->persistJob(ParseJob::STATUS_PROCESSING, 'recent', attempts: 0, maxAttempts: 1);

        $tester = $this->tester();
        $tester->execute(['--max-runtime' => 1, '--sleep' => 0]);

        $reloaded = $this->reload($job);
        $this->assertSame(ParseJob::STATUS_PROCESSING, $reloaded->getStatus());
        $this->assertNull($reloaded->getLastError());
    }

    public function test_verbose_output_includes_heartbeat(): void
    {
        $this->setStubExitCode(0);

        $tester = $this->tester();
        $tester->execute(
            ['--max-runtime' => 2, '--sleep' => 0],
            ['verbosity' => OutputInterface::VERBOSITY_VERBOSE],
        );

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Heartbeat', $display);
        $this->assertStringContainsString('pending:', $display);
    }

    private function tester(): CommandTester
    {
        $command = new ParseJobWorkerCommand($this->em(), $this->jobs(), 1, 30);
        $application = new Application(self::$kernel);
        $application->addCommand($command);

        return new CommandTester($command);
    }

    private function setStubExitCode(int $exitCode): void
    {
        $value = (string) $exitCode;
        putenv('PENNY_FAKE_PHP_EXIT='.$value);
        $_ENV['PENNY_FAKE_PHP_EXIT'] = $value;
        $_SERVER['PENNY_FAKE_PHP_EXIT'] = $value;
    }

    private function persistJob(string $status, string $rawText, int $attempts = 0, int $maxAttempts = 1): ParseJob
    {
        $job = new ParseJob();
        $job->setRawText($rawText);
        $job->setStatus($status);
        $job->setAttempts($attempts);
        $job->setMaxAttempts($maxAttempts);

        $em = $this->em();
        $em->persist($job);
        $em->flush();

        return $job;
    }

    /**
     * Lifecycle callbacks restamp updated_at on every flush, so bypass the ORM.
     */
    private function ageUpdatedAt(ParseJob $job, DateTimeImmutable $updatedAt): void
    {
        $this->em()->getConnection()->executeStatement(
            'UPDATE parse_job SET updated_at = ? WHERE id = ?',
            [$updatedAt->format('Y-m-d H:i:s'), $job->getId()],
        );
        $this->em()->clear();
    }

    private function forceAttempts(ParseJob $job, int $attempts): void
    {
        $this->em()->getConnection()->executeStatement(
            'UPDATE parse_job SET attempts = ? WHERE id = ?',
            [$attempts, $job->getId()],
        );
        $this->em()->clear();
    }

    private function reload(ParseJob $job): ParseJob
    {
        $this->em()->clear();
        $reloaded = $this->jobs()->find($job->getId());
        $this->assertInstanceOf(ParseJob::class, $reloaded);

        return $reloaded;
    }

    private function removeStubDir(): void
    {
        if (!is_dir($this->stubDir)) {
            return;
        }

        foreach (glob($this->stubDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->stubDir);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function jobs(): ParseJobRepository
    {
        return static::getContainer()->get(ParseJobRepository::class);
    }
}
