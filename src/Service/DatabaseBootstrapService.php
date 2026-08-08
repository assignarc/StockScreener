<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Centralized Just-In-Time (JIT) Database Provisioning & Auto-Seeding Service.
 *
 * All DDL statements and initial default seed values are centralized here.
 * If var/data.db or any tables are missing on boot or request, they are
 * automatically created and seeded without manual migrations or scripts.
 */
class DatabaseBootstrapService
{
    private static bool $bootstrapped = false;

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
        private string $projectDir,
    ) {}

    /**
     * Just-In-Time schema guarantee. Checks tables and provisions immediately if missing.
     */
    public function ensureSchemaAndSeed(): void
    {
        if (self::$bootstrapped) {
            return;
        }

        try {
            $this->ensureDatabaseFileExists();
            $this->provisionTables();
            $this->seedInitialData();
            self::$bootstrapped = true;
        } catch (\Throwable $e) {
            $this->logger->error('DatabaseBootstrapService error: ' . $e->getMessage());
        }
    }

    private function ensureDatabaseFileExists(): void
    {
        $varDir = $this->projectDir . '/var';
        if (!is_dir($varDir)) {
            @mkdir($varDir, 0777, true);
        }
        $dbFile = $varDir . '/data.db';
        if (!file_exists($dbFile)) {
            @touch($dbFile);
        }
    }

    private function provisionTables(): void
    {
        // 1. app_config table
        $this->connection->executeStatement('
            CREATE TABLE IF NOT EXISTS app_config (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                config_key VARCHAR(255) NOT NULL,
                config_value CLOB DEFAULT NULL,
                updated_at DATETIME NOT NULL
            )
        ');
        $this->connection->executeStatement('
            CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_APP_CONFIG_KEY ON app_config (config_key)
        ');

        // 2. persistent_cache table
        $this->connection->executeStatement('
            CREATE TABLE IF NOT EXISTS persistent_cache (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                cache_key VARCHAR(255) NOT NULL,
                cache_value CLOB NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                is_sensitive BOOLEAN DEFAULT 0 NOT NULL
            )
        ');
        $this->connection->executeStatement('
            CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_PERSISTENT_CACHE_KEY ON persistent_cache (cache_key)
        ');
        $this->connection->executeStatement('
            CREATE INDEX IF NOT EXISTS idx_cache_expires ON persistent_cache (expires_at)
        ');

        // 3. stock table
        $this->connection->executeStatement('
            CREATE TABLE IF NOT EXISTS stock (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                symbol VARCHAR(10) NOT NULL,
                name VARCHAR(255) NOT NULL,
                price DOUBLE PRECISION NOT NULL,
                change_percent DOUBLE PRECISION NOT NULL,
                target_price DOUBLE PRECISION NOT NULL,
                upside_potential DOUBLE PRECISION NOT NULL,
                sector VARCHAR(100) DEFAULT NULL,
                last_updated DATETIME NOT NULL
            )
        ');
        $this->connection->executeStatement('
            CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_STOCK_SYMBOL ON stock (symbol)
        ');

        // 4. watchlist table
        $this->connection->executeStatement('
            CREATE TABLE IF NOT EXISTS watchlist (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                symbol VARCHAR(10) NOT NULL,
                added_at DATETIME NOT NULL
            )
        ');
        $this->connection->executeStatement('
            CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_WATCHLIST_SYMBOL ON watchlist (symbol)
        ');
    }

    private function seedInitialData(): void
    {
        // ── Seed App Configurations ──────────────────────────────────────────
        $defaultConfigs = [
            'app.setup_completed'                   => false,
            'broker.active_provider'                => 'schwab',
            'finnhub.api_key'                       => $_ENV['FINNHUB_API_KEY'] ?? null,
            'schwab.app_key'                        => $_ENV['SCHWAB_APP_KEY'] ?? null,
            'schwab.app_secret'                     => $_ENV['SCHWAB_APP_SECRET'] ?? null,
            'gemini.api_key'                        => $_ENV['GEMINI_API_KEY'] ?? null,
            'flywheel.covered_call.min_shares'      => 100,
            'flywheel.covered_call.otm_pct'         => 0.06,
            'flywheel.covered_call.cost_basis_buffer'=> 1.02,
            'flywheel.covered_call.dte_target'      => 35,
            'flywheel.covered_call.est_premium_pct' => 0.028,
            'flywheel.early_exit.btc_profit_threshold'=> 50.0,
            'calendar.months_back'                  => 1,
            'calendar.months_forward'               => 6,
            'screener.suggest.target_price_factor'  => 1.22,
        ];

        $now = date('Y-m-d H:i:s');
        foreach ($defaultConfigs as $key => $val) {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(id) FROM app_config WHERE config_key = ?',
                [$key]
            );
            if ($exists === 0) {
                $jsonVal = json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $this->connection->executeStatement(
                    'INSERT INTO app_config (config_key, config_value, updated_at) VALUES (?, ?, ?)',
                    [$key, $jsonVal, $now]
                );
            }
        }

        // ── Seed Core Watchlist Tickers ───────────────────────────────────────
        $defaultStocks = [
            ['NVDA',  'NVIDIA Corporation',       128.50, 2.45, 155.00, 20.62, 'Technology'],
            ['AAPL',  'Apple Inc.',               224.23, 0.85, 260.00, 15.95, 'Technology'],
            ['MSFT',  'Microsoft Corporation',    448.37, 1.12, 500.00, 11.51, 'Technology'],
            ['GOOGL', 'Alphabet Inc.',            182.30, -0.40,215.00, 17.93, 'Communication Services'],
            ['AMZN',  'Amazon.com Inc.',          184.20, 1.30, 220.00, 19.43, 'Consumer Cyclical'],
            ['META',  'Meta Platforms Inc.',      510.60, 3.10, 580.00, 13.59, 'Communication Services'],
            ['AVGO',  'Broadcom Inc.',            168.40, 2.15, 195.00, 15.79, 'Technology'],
            ['JPM',   'JPMorgan Chase & Co.',     214.50, 0.45, 235.00, 9.55,  'Financial Services'],
            ['TSLA',  'Tesla Inc.',               245.00, 4.20, 300.00, 22.45, 'Consumer Cyclical'],
        ];

        foreach ($defaultStocks as $s) {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(id) FROM stock WHERE symbol = ?',
                [$s[0]]
            );
            if ($exists === 0) {
                $this->connection->executeStatement(
                    'INSERT INTO stock (symbol, name, price, change_percent, target_price, upside_potential, sector, last_updated)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$s[0], $s[1], $s[2], $s[3], $s[4], $s[5], $s[6], $now]
                );
            }
        }
    }

    /**
     * Checks if initial setup has been completed by the user
     */
    public function isSetupCompleted(): bool
    {
        $this->ensureSchemaAndSeed();
        $val = $this->connection->fetchOne(
            'SELECT config_value FROM app_config WHERE config_key = ?',
            ['app.setup_completed']
        );
        return $val !== false && json_decode($val, true) === true;
    }

    /**
     * Returns schema health and table counts
     */
    public function getSchemaStatus(): array
    {
        $this->ensureSchemaAndSeed();
        return [
            'databaseFile'   => 'var/data.db',
            'databaseSizeKb' => round((filesize($this->projectDir . '/var/data.db') ?: 0) / 1024, 1),
            'tables' => [
                'app_config'       => (int) $this->connection->fetchOne('SELECT COUNT(id) FROM app_config'),
                'persistent_cache' => (int) $this->connection->fetchOne('SELECT COUNT(id) FROM persistent_cache'),
                'stock'            => (int) $this->connection->fetchOne('SELECT COUNT(id) FROM stock'),
                'watchlist'        => (int) $this->connection->fetchOne('SELECT COUNT(id) FROM watchlist'),
            ],
            'setupCompleted' => $this->isSetupCompleted(),
        ];
    }
}
