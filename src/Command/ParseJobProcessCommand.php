<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ParseJob;
use App\Entity\Receipt;
use App\Repository\ParseJobRepository;
use App\Service\LLM\LlmClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:parse-jobs:process',
    description: 'Process a single parse job by calling the LLM.',
)]
class ParseJobProcessCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ParseJobRepository $parseJobRepository,
        private readonly LlmClient $llmClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('job-id', InputArgument::REQUIRED, 'The parse job ID to process');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $jobId = (int) $input->getArgument('job-id');

        $job = $this->parseJobRepository->find($jobId);

        if ($job === null) {
            $io->error(sprintf('Parse job #%d not found', $jobId));
            return Command::FAILURE;
        }

        if ($job->getStatus() !== ParseJob::STATUS_PROCESSING) {
            $io->warning(sprintf('Parse job #%d is not in processing state (current: %s), skipping', $jobId, $job->getStatus()));
            return Command::SUCCESS;
        }

        $io->text(sprintf(
            '[%s] Processing job #%d (attempt %d/%d): %s',
            date('Y-m-d H:i:s'),
            $job->getId(),
            $job->getAttempts() + 1,
            $job->getMaxAttempts(),
            mb_substr($job->getRawText() ?? '', 0, 80)
        ));

        // Increment attempt counter
        $job->incrementAttempts();
        $this->entityManager->flush();

        try {
            $systemPrompt = $this->buildSystemPrompt();
            $parsed = $this->llmClient->chat([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $job->getRawText() ?? ''],
            ]);

            $receipt = $this->createReceiptFromParsed($parsed, $job->getRawText() ?? '');

            $this->entityManager->persist($receipt);

            $job->setReceipt($receipt);
            $job->setStatus(ParseJob::STATUS_COMPLETED);
            $job->setCompletedAt(new \DateTimeImmutable());
            $job->setLastError(null);

            $this->entityManager->flush();

            $io->success(sprintf(
                'Job #%d completed: %s — $%s (%s)',
                $jobId,
                $receipt->getBusiness(),
                $receipt->getAmount(),
                $receipt->getCategory()
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();

            if ($job->getAttempts() >= $job->getMaxAttempts()) {
                $job->setStatus(ParseJob::STATUS_FAILED);
            } else {
                $job->setStatus(ParseJob::STATUS_PENDING);
            }

            $job->setLastError($errorMessage);
            $this->entityManager->flush();

            $io->error(sprintf(
                'Job #%d failed (attempt %d/%d): %s',
                $jobId,
                $job->getAttempts(),
                $job->getMaxAttempts(),
                $errorMessage
            ));

            return Command::FAILURE;
        }
    }

    /**
     * @param array<string, mixed> $parsed
     */
    private function createReceiptFromParsed(array $parsed, string $rawText): Receipt
    {
        $receipt = new Receipt();
        $receipt->setAmount(
            isset($parsed['amount']) && is_numeric($parsed['amount'])
                ? number_format((float) $parsed['amount'], 2, '.', '')
                : '0.00'
        );
        $receipt->setBusiness($parsed['business'] ?? 'Unknown');
        $receipt->setCategory($parsed['category'] ?? 'Other');
        $receipt->setLocation($parsed['location'] ?? null);
        $receipt->setTags($parsed['tags'] ?? []);
        $receipt->setNotes($parsed['notes'] ?? $rawText);
        $receipt->setRawInput($rawText);

        if (!empty($parsed['date'])) {
            try {
                $parsedDate = new \DateTimeImmutable($parsed['date']);

                // If the LLM returned a date without a time component (midnight),
                // fall back to the current time so receipts don't all get
                // timestamped to 00:00:00.
                if ($parsedDate->format('H:i:s') === '00:00:00') {
                    $parsedDate = new \DateTimeImmutable();
                }

                $receipt->setCreatedAt($parsedDate);
            } catch (\Throwable) {
                // keep default
            }
        }

        return $receipt;
    }

    private function buildSystemPrompt(): string
    {
        $now = new \DateTimeImmutable();
        $nowFormatted = $now->format('Y-m-d\\TH:i:s');

        return <<<PROMPT
You are a receipt parsing assistant. Extract expense details from the user's message and return ONLY a JSON object with these fields:
- amount: number (required, in dollars)
- business: string (required, merchant name)
- category: string (required, one of: Food, Transport, Utilities, Entertainment, Shopping, Health, Other)
- location: string or null
- tags: array of strings or empty array
- notes: string or null (include the original message here)
- date: ISO 8601 datetime string (e.g. "{$nowFormatted}") or null

Rules:
- If a field cannot be determined, use null (except amount, business, category which are required)
- For category, choose the best fit; default to "Other" if uncertain
- Normalize business names (capitalize properly)
- Current date and time is: {$nowFormatted}
- When the user says "today" without a specific time, use: {$nowFormatted}
- When the user says "yesterday", subtract one day but keep a reasonable time of day
- Infer the time of day from context when possible (e.g. "lunch" → around 12:00, "dinner" → around 19:00, "morning coffee" → around 08:00)
- Always include a time component in the datetime, never just a date

Respond with ONLY the JSON object, no markdown, no explanation.
PROMPT;
    }
}
