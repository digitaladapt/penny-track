<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ApiKey;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:api-key:create',
    description: 'Create a new API key (admin by default, --read-only for read-only access).',
)]
class CreateApiKeyCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('read-only', null, InputOption::VALUE_NONE, 'Create a read-only key (GET/HEAD only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $key = bin2hex(random_bytes(32));
        $hash = password_hash($key, \PASSWORD_BCRYPT);

        $apiKey = new ApiKey();
        $apiKey->setKeyHash($hash);
        $apiKey->setReadOnly((bool) $input->getOption('read-only'));

        $this->entityManager->persist($apiKey);
        $this->entityManager->flush();

        $type = $apiKey->isReadOnly() ? 'READ-ONLY' : 'ADMIN';

        $io->success(\sprintf('Created %s API key (id #%d)', $type, $apiKey->getId()));
        $io->text('Store this key now — it is shown only once:');
        $io->writeln('');
        $io->writeln('    '.$key);
        $io->writeln('');
        $io->text('Use it via the X-API-Key header, e.g.:');
        $io->writeln('    curl -H "X-API-Key: '.$key.'" https://host/api/receipts');

        return Command::SUCCESS;
    }
}
