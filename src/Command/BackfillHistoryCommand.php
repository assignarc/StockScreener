<?php

namespace App\Command;

use App\Service\BrokerManagerService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * BackfillHistoryCommand
 *
 * CLI console command to backfill and synchronize historical broker transactions into SQLite.
 */
#[AsCommand(
    name: 'app:broker:backfill-history',
    description: 'Backfills transaction history for all configured brokers for a given number of days.'
)]
class BackfillHistoryCommand extends Command
{
    /**
     * Initializes the command with broker manager and logger services.
     *
     * @param BrokerManagerService $brokerManager Multi-broker management service.
     * @param LoggerInterface      $logger        PSR-3 logger instance.
     */
    public function __construct(
        private BrokerManagerService $brokerManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    /**
     * Configures command options and flags.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->addOption(
            'days',
            'd',
            InputOption::VALUE_REQUIRED,
            'Number of days of history to backfill',
            730
        );
    }

    /**
     * Executes the transaction backfill process across all authorized brokers.
     *
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     * @return int Command exit status code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = (int) $input->getOption('days');
        
        $io->title("Broker Transaction History Backfiller");
        $io->note("Querying last {$days} days of transactions across all authorized brokers...");

        try {
            $history = $this->brokerManager->getAggregatedHistory($days, true);
            $io->success(sprintf("Backfilled %d historical transactions successfully.", count($history)));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error("Backfill failed: " . $e->getMessage());
            $this->logger->error("History backfill command failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
