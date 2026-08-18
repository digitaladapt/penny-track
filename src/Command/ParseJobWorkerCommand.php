<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ParseJob;
use App\Repository\ParseJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:parse-jobs:worker',
    description: 'Long-running worker that dispatches parse jobs to subprocesses.',
)]
class ParseJobWorkerCommand extends Command
{
    /** @var array<int, array{process: Process, job_id: int, started_at: \DateTimeImmutable}> */
    private array $runningJobs = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ParseJobRepository $parseJobRepository,
        private readonly int $maxBackgroundJobs,
        private readonly int $llmWorkerTimeout,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'sleep',
            's',
            InputOption::VALUE_OPTIONAL,
            'Seconds to sleep between polling cycles',
            5
        );
        $this->addOption(
            'max-runtime',
            null,
            InputOption::VALUE_OPTIONAL,
            'Maximum runtime in seconds before graceful exit (0 = unlimited)',
            0
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sleepSeconds = (int) $input->getOption('sleep');
        $maxRuntime = (int) $input->getOption('max-runtime');
        $startTime = time();

        $io->title('Parse Job Worker');
        $io->text(sprintf(
            'Max background jobs: <comment>%d</comment> | LLM timeout: <comment>%ds</comment> | Poll interval: <comment>%ds</comment>',
            $this->maxBackgroundJobs,
            $this->llmWorkerTimeout,
            $sleepSeconds
        ));

        $cycle = 0;

        while (true) {
            $cycle++;

            // Check max runtime
            if ($maxRuntime > 0 && (time() - $startTime) >= $maxRuntime) {
                $io->note('Max runtime reached, waiting for running jobs to finish...');
                $this->waitForRunningJobs($io);
                $io->success('Worker exiting after max runtime.');
                return Command::SUCCESS;
            }

            // 1. Reap finished subprocesses
            $this->reapFinishedJobs($io);

            // 2. Reap stale processing jobs (subprocess died)
            $this->reapStaleJobs($io);

            // 3. Count active subprocesses
            $activeCount = count($this->runningJobs);

            // 4. Spawn new jobs if under the limit
            while ($activeCount < $this->maxBackgroundJobs) {
                $this->entityManager->clear();
                $job = $this->parseJobRepository->claimNextPending();

                if ($job === null) {
                    break;
                }

                $this->spawnJob($job, $io);
                $activeCount++;
            }

            if ($output->isVerbose() && $cycle % 12 === 0) {
                // Every ~60 seconds (at 5s sleep), print a heartbeat
                $pending = $this->parseJobRepository->countPending();
                $processing = $this->parseJobRepository->countProcessing();
                $io->text(sprintf(
                    '[%s] Heartbeat — active: %d, processing: %d, pending: %d',
                    date('Y-m-d H:i:s'),
                    count($this->runningJobs),
                    $processing,
                    $pending
                ));
            }

            sleep($sleepSeconds);
        }
    }

    private function spawnJob(ParseJob $job, SymfonyStyle $io): void
    {
        $io->text(sprintf(
            '[%s] Spawning subprocess for job #%d (attempt %d/%d)',
            date('Y-m-d H:i:s'),
            $job->getId(),
            $job->getAttempts() + 1,
            $job->getMaxAttempts()
        ));

        $process = new Process([
            'php',
            'bin/console',
            'app:parse-jobs:process',
            (string) $job->getId(),
        ]);

        $projectDir = $this->getApplication()?->getKernel()->getProjectDir();
        if ($projectDir !== null) {
            $process->setWorkingDirectory($projectDir);
        }

        $process->setTimeout(null);
        $process->disableOutput();
        $process->start();

        $this->runningJobs[] = [
            'process' => $process,
            'job_id' => $job->getId(),
            'started_at' => new \DateTimeImmutable(),
        ];
    }

    private function reapFinishedJobs(SymfonyStyle $io): void
    {
        $stillRunning = [];

        foreach ($this->runningJobs as $entry) {
            if (!$entry['process']->isRunning()) {
                $exitCode = $entry['process']->getExitCode();
                $io->text(sprintf(
                    '[%s] Job #%d subprocess exited with code %d',
                    date('Y-m-d H:i:s'),
                    $entry['job_id'],
                    $exitCode
                ));

                if ($exitCode !== 0) {
                    // Subprocess failed — ensure the job isn't stuck in processing
                    $this->markFailedIfStuck($entry['job_id'], 'Subprocess exited with code ' . $exitCode, $io);
                }
            } else {
                $stillRunning[] = $entry;
            }
        }

        $this->runningJobs = $stillRunning;
    }

    private function reapStaleJobs(SymfonyStyle $io): void
    {
        // Stale = processing for longer than LLM_TIMEOUT + 120s buffer
        $threshold = new \DateInterval('PT' . ($this->llmWorkerTimeout + 120) . 'S');
        $staleJobs = $this->parseJobRepository->findStaleProcessing($threshold);

        foreach ($staleJobs as $job) {
            // Check if we have a running subprocess for this job
            $hasSubprocess = false;
            foreach ($this->runningJobs as $entry) {
                if ($entry['job_id'] === $job->getId()) {
                    $hasSubprocess = true;
                    break;
                }
            }

            if (!$hasSubprocess) {
                // No live subprocess but still in processing — it died
                $this->markFailedIfStuck($job->getId(), 'Process died unexpectedly (stale job detected)', $io);
            }
        }
    }

    private function markFailedIfStuck(int $jobId, string $error, SymfonyStyle $io): void
    {
        $this->entityManager->clear();
        $job = $this->parseJobRepository->find($jobId);

        if ($job === null || $job->getStatus() !== ParseJob::STATUS_PROCESSING) {
            return;
        }

        if ($job->getAttempts() >= $job->getMaxAttempts()) {
            $job->setStatus(ParseJob::STATUS_FAILED);
        } else {
            $job->setStatus(ParseJob::STATUS_PENDING);
        }

        $job->setLastError($error);
        $this->entityManager->flush();

        $io->warning(sprintf('Job #%d: %s', $jobId, $error));
    }

    private function waitForRunningJobs(SymfonyStyle $io): void
    {
        while (!empty($this->runningJobs)) {
            $this->reapFinishedJobs($io);
            $this->reapStaleJobs($io);

            if (!empty($this->runningJobs)) {
                $io->text(sprintf(
                    '[%s] Waiting for %d running job(s) to finish...',
                    date('Y-m-d H:i:s'),
                    count($this->runningJobs)
                ));
                sleep(5);
            }
        }
    }
}
