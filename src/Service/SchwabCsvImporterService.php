<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use App\Service\BrokerManagerService;
use App\Service\FinnhubCleanupService;

/**
 * SchwabCsvImporterService
 *
 * Ingests, parses, cleans, and deduplicates historical transaction exports
 * from Charles Schwab CSV files into the portfolio events database table.
 */
class SchwabCsvImporterService
{
    /**
     * Initializes the Schwab CSV importer service.
     *
     * @param Connection            $connection     Doctrine DBAL connection.
     * @param BrokerManagerService  $brokerManager  Broker management service for account resolution.
     * @param FinnhubCleanupService $cleanupService Data enrichment and symbol cleanup service.
     * @param LoggerInterface       $logger         PSR-3 logger instance.
     */
    public function __construct(
        private Connection $connection,
        private BrokerManagerService $brokerManager,
        private FinnhubCleanupService $cleanupService,
        private LoggerInterface $logger
    ) {}

    /**
     * Parses a Schwab export CSV file and imports transactions into portfolio_events.
     * Enforces immutable transaction records, exact account alignment, and duplicate checking.
     *
     * @param string $filePath       Absolute path to the Schwab CSV file.
     * @param string $defaultAccount Default account nickname/identifier to assign if unspecified.
     * @return array Import summary containing success status, imported count, and skipped count.
     */
    public function importCsv(string $filePath, string $defaultAccount = 'V-Brokerage'): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return ['error' => 'File not found or unreadable: ' . $filePath];
        }

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return ['error' => 'Failed to open file: ' . $filePath];
        }

        // Map known active accounts to resolve account nickname / number
        $accountMap = $this->buildAccountMap();

        $importedCount = 0;
        $skippedCount = 0;
        $now = date('Y-m-d H:i:s');
        $headerFound = false;
        $headerMap = [];

        // Auto-detect account name from filename or default parameter
        $cleanAccount = preg_replace('/_Transactions.*$/i', '', basename($defaultAccount));
        $cleanAccount = preg_replace('/_XXX\d+$/i', '', $cleanAccount);
        $detectedAccount = $cleanAccount !== '' ? $cleanAccount : $defaultAccount;

        while (($row = fgetcsv($handle, 4096, ',')) !== false) {
            if (empty($row) || (count($row) === 1 && trim($row[0]) === '')) {
                continue;
            }

            // Header auto-detection
            if (!$headerFound) {
                $lineStr = implode(' ', $row);
                if (preg_match('/for\s+account\s+([^\s,]+)/i', $lineStr, $accMatch)) {
                    $detectedAccount = trim($accMatch[1]);
                }

                $firstCell = strtoupper(trim($row[0] ?? ''));
                if ($firstCell === 'DATE' || in_array('ACTION', array_map('strtoupper', $row))) {
                    $headerFound = true;
                    foreach ($row as $idx => $header) {
                        $headerMap[strtoupper(trim($header))] = $idx;
                    }
                    continue;
                }
                continue;
            }

            // Stop reading if footer disclaimer / total reached
            if (str_contains(strtoupper($row[0] ?? ''), 'DISCLAIMER') || str_contains(strtoupper($row[0] ?? ''), 'TRANSACTIONS TOTAL')) {
                break;
            }

            $dateRaw = $row[$headerMap['DATE'] ?? 0] ?? '';
            if (!$dateRaw || strtoupper($dateRaw) === 'DATE') {
                continue;
            }

            $dateObj = \DateTimeImmutable::createFromFormat('m/d/Y', trim($dateRaw)) 
                ?: \DateTimeImmutable::createFromFormat('Y-m-d', trim($dateRaw))
                ?: \DateTimeImmutable::createFromFormat('m/d/y', trim($dateRaw));

            if (!$dateObj) {
                continue;
            }

            $date = $dateObj->format('Y-m-d');
            $action = strtoupper(trim($row[$headerMap['ACTION'] ?? 1] ?? ''));
            $symbol = strtoupper(trim($row[$headerMap['SYMBOL'] ?? 2] ?? ''));
            $desc = trim($row[$headerMap['DESCRIPTION'] ?? 3] ?? '');
            
            $qtyRaw = $row[$headerMap['QUANTITY'] ?? -1] ?? '0';
            $priceRaw = $row[$headerMap['PRICE'] ?? -1] ?? '0';
            $feesRaw = $row[$headerMap['FEES & COMM'] ?? $headerMap['FEES'] ?? -1] ?? '0';
            $amountRaw = $row[$headerMap['AMOUNT'] ?? 4] ?? '0';

            // Clean currency and numeric values
            $amount = (float) preg_replace('/[^\d\.\-]/', '', $amountRaw);
            $qty = (float) preg_replace('/[^\d\.\-]/', '', $qtyRaw);
            $price = (float) preg_replace('/[^\d\.\-]/', '', $priceRaw);
            $fees = (float) preg_replace('/[^\d\.\-]/', '', $feesRaw);

            if (!$symbol && preg_match('/^[A-Z]{1,5}\b/', $desc, $m)) {
                $candidate = $m[0];
                if (!in_array($candidate, ['SEC', 'ADR', 'BANK', 'WIRE', 'CASH', 'TRF', 'FRM', 'FEE', 'INT', 'CHECK'])) {
                    $symbol = $candidate;
                }
            }
            if (!$symbol || in_array($symbol, ['SEC', 'ADR', 'BANK', 'WIRE', 'CASH', 'TRF', 'FRM', 'FEE', 'INT', 'CHECK'])) {
                $symbol = 'CASH';
            }

            // Normalize account alignment
            $rowAccount = $row[$headerMap['ACCOUNT'] ?? -1] ?? $detectedAccount;
            $resolvedAccount = $this->resolveAccount($rowAccount, $accountMap, $defaultAccount);

            $eventType = 'EQUITY';
            if (str_contains($action, 'DIV') || str_contains($desc, 'DIVIDEND')) {
                $eventType = 'DIVIDEND';
            } elseif (
                str_contains($action, 'OPTION') 
                || str_contains($desc, 'CALL') 
                || str_contains($desc, 'PUT') 
                || preg_match('/^[A-Z0-9]+\s*\d{6}[CP]\d{8}$/', $symbol)
            ) {
                $eventType = 'OPTION';
            }

            // Normalize and enrich transaction with Finnhub and canonical standards before saving
            $enriched = $this->cleanupService->normalizeAndEnrichTransaction([
                'symbol' => $symbol,
                'description' => $desc,
                'action' => $action,
                'type' => $eventType,
            ]);
            $symbol = $enriched['symbol'];
            $eventType = $enriched['event_type'];
            if (!empty($enriched['description'])) {
                $desc = $enriched['description'];
            }

            $title = "{$eventType}: {$symbol} (" . ($amount >= 0 ? '+' : '') . '$' . number_format($amount, 2) . ')';

            // Calculate item cost basis
            $costBasis = ($price > 0 && $qty != 0) ? abs($qty * $price) : abs($amount);

            try {
                // Check if identical transaction already exists in database (matching exact symbol or equivalent canonical symbol)
                $normSym = TaxEngine::normalizeSymbol($symbol);
                $readableSym = TaxEngine::formatOptionReadable($normSym);
                $existing = (int) $this->connection->fetchOne(
                    'SELECT COUNT(id) FROM portfolio_events 
                     WHERE event_date = :date 
                       AND account_number = :acc 
                       AND ABS(impact_amount - :amount) < 0.01 
                       AND (symbol = :symbol OR symbol = :normSym OR symbol = :readableSym)',
                    [
                        'date' => $date,
                        'symbol' => $symbol,
                        'normSym' => $normSym,
                        'readableSym' => $readableSym,
                        'acc' => $resolvedAccount,
                        'amount' => $amount,
                    ]
                );

                if ($existing > 0) {
                    $skippedCount++;
                    continue;
                }

                $this->connection->executeStatement('
                    INSERT INTO portfolio_events 
                    (event_date, event_type, provider, account_number, symbol, impact_amount, quantity, price, fees, action, impact_pct, cost_basis, realized_gain, est_tax, title, description, created_at)
                    VALUES (:date, :type, "Schwab", :accNum, :symbol, :amount, :qty, :price, :fees, :action, 0.0, :costBasis, 0.0, 0.0, :title, :desc, :createdAt)
                ', [
                    'date'      => $date,
                    'type'      => $eventType,
                    'accNum'    => $resolvedAccount,
                    'symbol'    => $symbol,
                    'amount'    => $amount,
                    'qty'       => $qty,
                    'price'     => $price,
                    'fees'      => $fees,
                    'action'    => $action,
                    'costBasis' => $costBasis,
                    'title'     => $title,
                    'desc'      => $desc !== '' ? $desc : $action,
                    'createdAt' => $now,
                ]);

                $importedCount++;
            } catch (\Throwable $e) {
                $this->logger->warning('Schwab CSV import row skipped: ' . $e->getMessage());
                $skippedCount++;
            }
        }

        fclose($handle);

        return [
            'success' => true,
            'imported' => $importedCount,
            'skipped' => $skippedCount,
        ];
    }

    /**
     * Builds a dictionary of known accounts from the active portfolio to ensure clean alignment.
     *
     * @return array Map of uppercase account identifiers/nicknames to canonical names.
     */
    private function buildAccountMap(): array
    {
        $map = [];
        try {
            $portfolio = $this->brokerManager->getAggregatedPortfolio();
            foreach ($portfolio['accounts'] ?? [] as $acc) {
                $num = (string) ($acc['accountNumber'] ?? '');
                $nick = (string) ($acc['nickname'] ?? '');
                $last4 = substr($num, -4);

                if ($nick !== '') {
                    $map[strtoupper($nick)] = $nick;
                }
                if ($num !== '') {
                    $map[strtoupper($num)] = $nick ?: $num;
                }
                if ($last4 !== '') {
                    $map['***' . $last4] = $nick ?: $num;
                    $map[$last4] = $nick ?: $num;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Could not build account map in CSV importer: ' . $e->getMessage());
        }

        return $map;
    }

    /**
     * Resolves incoming raw CSV account strings to the canonical account name.
     *
     * @param string $rawAccount     Raw account label from CSV row.
     * @param array  $accountMap     Map of recognized accounts.
     * @param string $defaultAccount Fallback default account.
     * @return string Canonical account identifier or nickname.
     */
    private function resolveAccount(string $rawAccount, array $accountMap, string $defaultAccount): string
    {
        $clean = trim($rawAccount);
        if ($clean === '') {
            return $defaultAccount;
        }

        $upper = strtoupper($clean);
        if (isset($accountMap[$upper])) {
            return $accountMap[$upper];
        }

        // Match last 4 digits if present
        if (preg_match('/(\d{4})$/', $clean, $m)) {
            $last4 = $m[1];
            if (isset($accountMap['***' . $last4])) {
                return $accountMap['***' . $last4];
            }
            if (isset($accountMap[$last4])) {
                return $accountMap[$last4];
            }
        }

        return $clean;
    }
}
