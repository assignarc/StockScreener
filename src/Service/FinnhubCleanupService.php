<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * FinnhubCleanupService
 *
 * Provides CUSIP resolution, option symbol canonicalization, Bank CD detection,
 * and corporate action / stock split audits using Finnhub market intelligence.
 */
class FinnhubCleanupService
{
    /**
     * Initializes the Finnhub cleanup and normalization service.
     *
     * @param Connection      $connection Doctrine DBAL connection.
     * @param FinnhubService  $finnhub    Finnhub market data client.
     * @param LoggerInterface $logger     PSR-3 logger instance.
     */
    public function __construct(
        private Connection $connection,
        private FinnhubService $finnhub,
        private LoggerInterface $logger,
    ) {}

    /**
     * Normalizes and enriches any raw transaction before saving into the database.
     * Ensures all future transactions from CSV imports or API syncs are clean and standardized.
     *
     * @param array $tx Raw transaction record.
     * @return array Enriched and standardized transaction record.
     */
    public function normalizeAndEnrichTransaction(array $tx): array
    {
        $rawSymbol = strtoupper(trim($tx['symbol'] ?? ''));
        $desc = trim($tx['description'] ?? $tx['title'] ?? '');
        $action = strtoupper(trim($tx['action'] ?? ''));
        $eventType = strtoupper(trim($tx['type'] ?? $tx['event_type'] ?? 'EQUITY'));

        // 1. Identify and normalize option contracts
        if (
            $eventType === 'OPTION'
            || preg_match('/^[A-Z0-9]+\s*\d{6}[CP]\d{8}$/i', $rawSymbol)
            || preg_match('/^[A-Z0-9]+\s+\d{2}\/\d{2}\/(?:20\d{2}|\d{2})\s+[\d\.]+\s+[CP]$/i', $rawSymbol)
            || str_contains(strtoupper($desc), 'CALL ')
            || str_contains(strtoupper($desc), 'PUT ')
        ) {
            $normSymbol = TaxEngine::normalizeOptionSymbol($rawSymbol);
            $readableSymbol = TaxEngine::formatOptionReadable($normSymbol);
            $tx['symbol'] = $readableSymbol;
            $tx['raw_symbol'] = $normSymbol;
            $tx['event_type'] = 'OPTION';
            return $tx;
        }

        // 2. Identify CUSIPs / Numeric Bank CD symbols
        if (preg_match('/^[0-9][0-9A-Z]{7,9}$/', $rawSymbol)) {
            // Check if description already indicates a Bank CD or Treasury
            if (stripos($desc, 'CD') !== false || stripos($desc, 'FDIC') !== false || stripos($desc, '%CD') !== false) {
                $bankName = 'BANK_CD';
                if (preg_match('/^([A-Z\s&]+?)(?:\s+\d|\s+%|\s+FDIC|\s+CD)/i', $desc, $m)) {
                    $bankName = 'CD:' . strtoupper(trim(preg_replace('/[^A-Z0-9]/', '', substr($m[1], 0, 12))));
                }
                $tx['symbol'] = $bankName;
                $tx['event_type'] = 'CD';
                return $tx;
            }

            // Look up via Finnhub CUSIP resolver
            $profile = $this->finnhub->lookupCusip($rawSymbol);
            if ($profile && !empty($profile['ticker'])) {
                $tx['symbol'] = $profile['ticker'];
                if (!empty($profile['name']) && empty($desc)) {
                    $tx['description'] = $profile['name'];
                }
                return $tx;
            }
        }

        // 3. Normalize standard equity share classes (e.g. BRK.B -> BRKB, MOG.A -> MOGA)
        $tx['symbol'] = TaxEngine::normalizeSymbol($rawSymbol);
        $tx['event_type'] = $eventType;

        return $tx;
    }

    /**
     * Resolves all CUSIP / numeric identifiers in portfolio_events to readable tickers / CD names.
     *
     * @param bool $dryRun If true, returns planned resolutions without writing updates to the database.
     * @return array List of resolved records with old and new symbols.
     */
    public function resolveExistingCusips(bool $dryRun = false): array
    {
        $rows = $this->connection->fetchAllAssociative('
            SELECT id, event_date, symbol, description, title, impact_amount 
            FROM portfolio_events 
            ORDER BY id ASC
        ');

        $resolved = [];
        $lookupCache = [];
        foreach ($rows as $r) {
            $id = $r['id'];
            $sym = strtoupper(trim($r['symbol'] ?? ''));
            $desc = $r['description'] ?? $r['title'] ?? '';

            if (preg_match('/^[0-9][0-9A-Z]{7,9}$/', $sym)) {
                $targetSym = null;
                $targetDesc = null;

                if (isset($lookupCache[$sym])) {
                    $targetSym = $lookupCache[$sym]['sym'];
                    $targetDesc = $lookupCache[$sym]['desc'] ?? $desc;
                } else {
                    // Check for Bank CD in description
                    if (stripos($desc, 'CD') !== false || stripos($desc, 'FDIC') !== false || stripos($desc, '%CD') !== false) {
                        $bank = 'BANK_CD';
                        if (preg_match('/^([A-Z\s&]+?)(?:\s+\d|\s+%|\s+FDIC|\s+CD)/i', $desc, $m)) {
                            $bank = 'CD:' . strtoupper(trim(preg_replace('/[^A-Z0-9]/', '', substr($m[1], 0, 12))));
                        }
                        $targetSym = $bank;
                        $targetDesc = $desc;
                    } else {
                        // Try Finnhub CUSIP lookup
                        $profile = $this->finnhub->lookupCusip($sym);
                        if ($profile && !empty($profile['ticker'])) {
                            $targetSym = $profile['ticker'];
                            $targetDesc = $profile['name'] ?? $desc;
                        }
                    }
                    $lookupCache[$sym] = ['sym' => $targetSym, 'desc' => $targetDesc];
                }

                if ($targetSym && $targetSym !== $sym) {
                    $resolved[] = [
                        'id' => $id,
                        'date' => $r['event_date'],
                        'oldSymbol' => $sym,
                        'newSymbol' => $targetSym,
                        'desc' => $targetDesc,
                    ];

                    if (!$dryRun) {
                        $this->connection->executeStatement('
                            UPDATE portfolio_events 
                            SET symbol = :newSym, description = :newDesc 
                            WHERE id = :id
                        ', [
                            'newSym' => $targetSym,
                            'newDesc' => $targetDesc,
                            'id' => $id,
                        ]);
                    }
                }
            }
        }

        return $resolved;
    }

    /**
     * Audits stock splits for traded symbols with multiple transactions and reports potential unadjusted pre-split lots.
     *
     * @return array Map of symbols to historical stock split records.
     */
    public function auditStockSplits(): array
    {
        $symbols = $this->connection->fetchFirstColumn('
            SELECT symbol 
            FROM portfolio_events 
            WHERE event_type = "EQUITY" 
              AND LENGTH(symbol) <= 5 
              AND symbol NOT LIKE "%CD%" 
              AND symbol NOT LIKE "%CASH%"
              AND symbol NOT LIKE "%/%"
            GROUP BY symbol
            HAVING COUNT(id) >= 2
            ORDER BY COUNT(id) DESC
            LIMIT 50
        ');

        $splitAudit = [];
        foreach ($symbols as $sym) {
            $splits = $this->finnhub->getStockSplits($sym, '2020-01-01', date('Y-m-d'));
            if (!empty($splits)) {
                $splitAudit[$sym] = $splits;
            }
        }

        return $splitAudit;
    }
}
