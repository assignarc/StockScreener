<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use App\Service\AppConfigService;

/**
 * DatabaseBootstrapService
 *
 * Centralized Just-In-Time (JIT) Database Provisioning and Auto-Seeding Service.
 *
 * All DDL statements, table definitions, index configurations, and initial seed
 * values are managed here. If var/data.db or any tables are missing on boot or
 * during a request, they are automatically provisioned without manual migrations.
 */
class DatabaseBootstrapService
{
    private static bool $bootstrapped = false;

    /**
     * Initializes the database bootstrap service.
     *
     * @param Connection      $connection Doctrine DBAL database connection.
     * @param LoggerInterface $logger     PSR-3 logger instance.
     * @param string          $projectDir Root directory of the Symfony project.
     */
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
        private string $projectDir,
    ) {}

    /**
     * Just-In-Time schema guarantee. Checks tables and provisions immediately if missing.
     *
     * @return void
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

    /**
     * Ensures the local SQLite database directory and file exist on disk.
     *
     * @return void
     */
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

    /**
     * Executes DDL statements to provision tables, indexes, and configure WAL mode pragmas.
     *
     * @return void
     */
    private function provisionTables(): void
    {
        // Enable SQLite high-concurrency PRAGMAs (WAL mode, busy timeout, synchronous)
        try {
            $this->connection->executeStatement('PRAGMA journal_mode = WAL;');
            $this->connection->executeStatement('PRAGMA busy_timeout = 5000;');
            $this->connection->executeStatement('PRAGMA synchronous = NORMAL;');
        } catch (\Throwable $e) {
            $this->logger->warning('Failed setting SQLite PRAGMAs: ' . $e->getMessage());
        }

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
        $this->connection->executeStatement('
            CREATE INDEX IF NOT EXISTS idx_cache_lookup ON persistent_cache (cache_key, expires_at)
        ');

        // 3. stock table
        $this->connection->executeStatement('
            CREATE TABLE IF NOT EXISTS stock (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                symbol VARCHAR(20) NOT NULL,
                name VARCHAR(255) NOT NULL,
                sector VARCHAR(100) NOT NULL,
                price DOUBLE PRECISION DEFAULT NULL,
                target_price DOUBLE PRECISION DEFAULT NULL,
                market_cap VARCHAR(50) DEFAULT NULL,
                rev_growth VARCHAR(50) DEFAULT NULL,
                gross_margin VARCHAR(50) DEFAULT NULL,
                cash_runway VARCHAR(50) DEFAULT NULL,
                short_interest VARCHAR(50) DEFAULT NULL,
                risk VARCHAR(20) NOT NULL DEFAULT "MED",
                score INTEGER NOT NULL DEFAULT 50,
                analyst_rating VARCHAR(50) DEFAULT NULL,
                thesis CLOB DEFAULT NULL,
                catalysts CLOB DEFAULT NULL,
                key_risks CLOB DEFAULT NULL
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

        // 5. portfolio_snapshots table
        $this->connection->executeStatement('
            CREATE TABLE IF NOT EXISTS portfolio_snapshots (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                snapshot_date DATE NOT NULL,
                total_value DOUBLE PRECISION NOT NULL,
                cash_balance DOUBLE PRECISION NOT NULL,
                equity_value DOUBLE PRECISION NOT NULL,
                option_value DOUBLE PRECISION NOT NULL,
                unrealized_pl DOUBLE PRECISION NOT NULL,
                est_tax_owed DOUBLE PRECISION DEFAULT 0.0 NOT NULL,
                benchmark_spy_price DOUBLE PRECISION DEFAULT NULL,
                created_at DATETIME NOT NULL
            )
        ');
        $this->connection->executeStatement('
            CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_PORTFOLIO_SNAPSHOT_DATE ON portfolio_snapshots (snapshot_date)
        ');

        // 6. portfolio_events table
        $this->connection->executeStatement('
            CREATE TABLE IF NOT EXISTS portfolio_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                event_date DATE NOT NULL,
                event_type VARCHAR(50) NOT NULL,
                provider VARCHAR(50) DEFAULT "Schwab",
                account_number VARCHAR(50) DEFAULT "PRIMARY",
                symbol VARCHAR(20) DEFAULT NULL,
                impact_amount DOUBLE PRECISION DEFAULT 0.0,
                impact_pct DOUBLE PRECISION DEFAULT 0.0,
                cost_basis DOUBLE PRECISION DEFAULT 0.0,
                realized_gain DOUBLE PRECISION DEFAULT 0.0,
                est_tax DOUBLE PRECISION DEFAULT 0.0,
                title VARCHAR(255) NOT NULL,
                description CLOB DEFAULT NULL,
                created_at DATETIME NOT NULL
            )
        ');
        try {
            $this->connection->executeStatement('ALTER TABLE portfolio_events ADD COLUMN provider VARCHAR(50) DEFAULT "Schwab"');
        } catch (\Throwable $e) {}
        try {
            $this->connection->executeStatement('ALTER TABLE portfolio_events ADD COLUMN account_number VARCHAR(50) DEFAULT "PRIMARY"');
        } catch (\Throwable $e) {}
        try {
            $this->connection->executeStatement('ALTER TABLE portfolio_events ADD COLUMN cost_basis DOUBLE PRECISION DEFAULT 0.0');
        } catch (\Throwable $e) {}

        $this->connection->executeStatement('
            CREATE INDEX IF NOT EXISTS idx_portfolio_events_date ON portfolio_events (event_date)
        ');
        $this->connection->executeStatement('
            CREATE INDEX IF NOT EXISTS idx_portfolio_events_type ON portfolio_events (event_type)
        ');
        $this->connection->executeStatement('
            CREATE INDEX IF NOT EXISTS idx_portfolio_events_provider ON portfolio_events (provider)
        ');
        $this->connection->executeStatement('
            CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_PORTFOLIO_EVENT_IDENTIFIER ON portfolio_events (event_date, symbol, title, account_number)
        ');
    }

    /**
     * Seeds initial default configurations, migrates legacy token files, and provisions core tickers.
     *
     * @return void
     */
    private function seedInitialData(): void
    {
        // Seed App Configurations
        // NOTE: Secrets (api keys) are intentionally seeded as null.
        // The Setup Wizard (/setup) is the only supported path to enter credentials.
        // No critical information lives in .env or git.
        $defaultConfigs = AppConfigService::DEFAULTS;

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

        // Migrate legacy var/schwab_token.json to encrypted DB storage
        $legacyTokenFile = $this->projectDir . '/var/schwab_token.json';
        if (file_exists($legacyTokenFile)) {
            $raw = @file_get_contents($legacyTokenFile);
            if (!empty($raw)) {
                $tokenData = json_decode($raw, true);
                if (is_array($tokenData) && !empty($tokenData['access_token'])) {
                    // Save to b1 oauth_token key if not already present
                    $existing = $this->connection->fetchOne('SELECT config_value FROM app_config WHERE config_key = ?', ['broker.b1.oauth_token']);
                    if (!$existing) {
                        $jsonVal = json_encode($tokenData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        $this->connection->executeStatement(
                            'INSERT INTO app_config (config_key, config_value, updated_at) VALUES (?, ?, ?)',
                            ['broker.b1.oauth_token', $jsonVal, $now]
                        );
                    }
                }
            }
            @unlink($legacyTokenFile);
        }

        // Seed Core Watchlist Tickers
        $defaultStocks = [
            ['NVDA',  'NVIDIA Corporation',       'Technology',             128.50, 155.00, '$3.16T', '+122.0%', '75.0%', '48 Months', '1.2%', 'LOW', 92, 'STRONG BUY', 'Market leader in AI datacenter GPUs.'],
            ['AAPL',  'Apple Inc.',               'Technology',             224.23, 260.00, '$3.44T', '+6.1%',   '46.3%', '36 Months', '0.8%', 'LOW', 88, 'BUY',        'Robust ecosystem with Apple Intelligence rollout.'],
            ['MSFT',  'Microsoft Corporation',    'Technology',             448.37, 500.00, '$3.33T', '+15.0%',  '70.1%', '60 Months', '0.5%', 'LOW', 90, 'STRONG BUY', 'Azure AI acceleration.'],
            ['GOOGL', 'Alphabet Inc.',            'Communication Services', 182.30, 215.00, '$2.26T', '+13.6%',  '57.4%', '48 Months', '0.6%', 'LOW', 85, 'BUY',        'Dominant search moat and Gemini enterprise integration.'],
            ['AMZN',  'Amazon.com Inc.',          'Consumer Cyclical',      184.20, 220.00, '$1.92T', '+12.5%',  '48.8%', '36 Months', '0.9%', 'LOW', 87, 'BUY',        'AWS cloud expansion and retail margins.'],
            ['META',  'Meta Platforms Inc.',      'Communication Services', 510.60, 580.00, '$1.29T', '+22.1%',  '81.8%', '36 Months', '1.1%', 'LOW', 89, 'BUY',        'Family of Apps monetization and AI advertising.'],
            ['AVGO',  'Broadcom Inc.',            'Technology',             168.40, 195.00, '$780B',  '+43.0%',  '64.0%', '30 Months', '1.4%', 'LOW', 86, 'BUY',        'Custom ASIC AI chips partner for hyper-scalers.'],
            ['JPM',   'JPMorgan Chase & Co.',     'Financial Services',     214.50, 235.00, '$610B',  '+11.0%',  '58.0%', 'N/A',       '0.7%', 'LOW', 82, 'BUY',        'Strong net interest income and balance sheet.'],
            ['TSLA',  'Tesla Inc.',               'Consumer Cyclical',      245.00, 300.00, '$780B',  '+8.0%',   '18.0%', '36 Months', '3.8%', 'HIGH', 68, 'HOLD',       'Autonomous driving catalysts and energy storage growth.'],
        ];

        foreach ($defaultStocks as $s) {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(id) FROM stock WHERE symbol = ?',
                [$s[0]]
            );
            if ($exists === 0) {
                $this->connection->executeStatement(
                    'INSERT INTO stock (symbol, name, sector, price, target_price, market_cap, rev_growth, gross_margin, cash_runway, short_interest, risk, score, analyst_rating, thesis)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$s[0], $s[1], $s[2], $s[3], $s[4], $s[5], $s[6], $s[7], $s[8], $s[9], $s[10], $s[11], $s[12], $s[13]]
                );
            }
        }
    }

    /**
     * Checks if initial setup has been completed by the user.
     *
     * @return bool True if initial setup wizard was completed, false otherwise.
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
     * Returns schema health, file size, and table row counts.
     *
     * @return array Schema status metrics and table counts.
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
