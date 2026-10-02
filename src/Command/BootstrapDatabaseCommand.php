<?php

namespace App\Command;

use App\Service\DatabaseBootstrapService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class BootstrapDatabaseCommand
 *
 * CLI command to verify, provision, and seed the local SQLite database schema.
 */
#[AsCommand(
    name: 'app:bootstrap-db',
    description: 'Initializes the SQLite schema, registers table indexes, and seeds default configurations and equity universe.'
)]
class BootstrapDatabaseCommand extends Command
{
    public function __construct(
        private DatabaseBootstrapService $bootstrap,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('StockScreener Local SQLite Database Bootstrapper');

        $io->text('Verifying SQLite schema, PRAGMA settings, tables, and seed data...');

        $this->bootstrap->ensureSchemaAndSeed();

        $status = $this->bootstrap->getSchemaStatus();

        $io->success('Database schema and initial seed data verified successfully.');

        $io->section('Database Health & Table Statistics');
        $io->listing([
            sprintf('Database File: %s (%s KB)', $status['databaseFile'], $status['databaseSizeKb']),
            sprintf('Setup Completed: %s', $status['setupCompleted'] ? 'Yes' : 'No (Pending /setup wizard)'),
        ]);

        $tableRows = [];
        foreach ($status['tables'] as $table => $count) {
            $tableRows[] = [$table, number_format($count)];
        }

        $io->table(['Table Name', 'Row Count'], $tableRows);

        return Command::SUCCESS;
    }
}
