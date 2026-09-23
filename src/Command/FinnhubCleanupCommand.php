<?php

namespace App\Command;

use App\Service\FinnhubCleanupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * FinnhubCleanupCommand
 *
 * Console command to reconcile and clean up portfolio transactions, CUSIP identifiers,
 * Bank CDs, and corporate action stock splits using Finnhub market APIs.
 */
#[AsCommand(
    name: 'app:finnhub:cleanup',
    description: 'Reconcile and cleanup portfolio transactions, CUSIPs, and stock splits using Finnhub API'
)]
class FinnhubCleanupCommand extends Command
{
    /**
     * Initializes the cleanup command.
     *
     * @param FinnhubCleanupService $cleanupService Finnhub normalization service.
     */
    public function __construct(
        private FinnhubCleanupService $cleanupService
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
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Run in simulation mode without writing database changes');
    }

    /**
     * Executes the Finnhub CUSIP resolution and stock split audit.
     *
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     * @return int Command exit status code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        $io->title('Finnhub Portfolio Data Cleanup & Reconciliation');
        if ($dryRun) {
            $io->warning('Running in DRY-RUN mode. No database records will be modified.');
        }

        // 1. Resolve CUSIPs / Numeric Bank IDs
        $io->section('1. Auditing & Resolving Cryptic CUSIP Identifiers');
        $resolvedCusips = $this->cleanupService->resolveExistingCusips($dryRun);

        if (empty($resolvedCusips)) {
            $io->success('All CUSIP and numeric identifiers are already clean and normalized.');
        } else {
            $tableRows = [];
            foreach ($resolvedCusips as $item) {
                $tableRows[] = [
                    $item['id'],
                    $item['date'],
                    $item['oldSymbol'],
                    $item['newSymbol'],
                    substr($item['desc'] ?? '', 0, 45),
                ];
            }
            $io->table(['ID', 'Date', 'Old Symbol / CUSIP', 'New Resolved Symbol', 'Description'], $tableRows);
            $io->success(sprintf('Successfully resolved %d CUSIP records%s.', count($resolvedCusips), $dryRun ? ' (simulated)' : ''));
        }

        // 2. Audit Stock Splits across Traded Symbols
        $io->section('2. Auditing Historical Stock Splits & Corporate Actions');
        $splits = $this->cleanupService->auditStockSplits();

        if (empty($splits)) {
            $io->note('No historical stock splits detected in portfolio history.');
        } else {
            $splitRows = [];
            foreach ($splits as $sym => $records) {
                foreach ($records as $sp) {
                    $splitRows[] = [
                        $sym,
                        $sp['date'] ?? 'N/A',
                        ($sp['fromFactor'] ?? 1) . ' : ' . ($sp['toFactor'] ?? 1),
                    ];
                }
            }
            $io->table(['Symbol', 'Split Date', 'Ratio (From : To)'], $splitRows);
            $io->success(sprintf('Audited corporate action split calendar for %d equities.', count($splits)));
        }

        $io->success('Portfolio cleanup and reconciliation completed successfully!');
        return Command::SUCCESS;
    }
}
