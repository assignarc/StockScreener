<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

class SchwabCsvImporterService
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger
    ) {}

    /**
     * Parses a Schwab export CSV file and imports transactions into portfolio_events.
     * Returns summary of imported/skipped records.
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

        $importedCount = 0;
        $skippedCount = 0;
        $now = date('Y-m-d H:i:s');
        $headerFound = false;
        $cleanAccount = preg_replace('/_Transactions.*$/i', '', basename($defaultAccount));
        $cleanAccount = preg_replace('/_XXX\d+$/i', '', $cleanAccount);
        $detectedAccount = $cleanAccount !== '' ? $cleanAccount : $defaultAccount;

        while (($row = fgetcsv($handle, 4096, ',')) !== false) {
            if (empty($row) || (count($row) === 1 && trim($row[0]) === '')) {
                continue;
            }

            // Clean BOM and spaces from cells
            $row = array_map(function ($col) {
                return trim(preg_replace('/\x{FEFF}/u', '', $col));
            }, $row);

            // Auto-detect account title/number from Schwab top metadata lines (e.g. "Transactions for account ...")
            if (!$headerFound) {
                $lineStr = implode(' ', $row);
                if (preg_match('/for\s+account\s+([^\s,]+)/i', $lineStr, $accMatch)) {
                    $detectedAccount = trim($accMatch[1]);
                }

                $firstCell = strtoupper($row[0] ?? '');
                if ($firstCell === 'DATE' || in_array('ACTION', array_map('strtoupper', $row)) || in_array('SYMBOL', array_map('strtoupper', $row))) {
                    $headerFound = true;
                    foreach ($row as $idx => $colName) {
                        $headerMap[strtoupper($colName)] = $idx;
                    }
                }
                continue;
            }

            // Stop reading if footer text reached
            if (str_contains(strtoupper($row[0] ?? ''), 'DISCLAIMER') || str_contains(strtoupper($row[0] ?? ''), 'TRANSACTIONS TOTAL')) {
                break;
            }

            $dateRaw = $row[$headerMap['DATE'] ?? 0] ?? '';
            if (empty($dateRaw)) continue;

            $dateObj = \DateTimeImmutable::createFromFormat('m/d/Y', $dateRaw) 
                ?: \DateTimeImmutable::createFromFormat('Y-m-d', $dateRaw)
                ?: null;
            if (!$dateObj) continue;

            $date = $dateObj->format('Y-m-d');
            $action = strtoupper($row[$headerMap['ACTION'] ?? $headerMap['TRANSACTION TYPE'] ?? 1] ?? '');
            $symbol = strtoupper($row[$headerMap['SYMBOL'] ?? 2] ?? '');
            $desc = $row[$headerMap['DESCRIPTION'] ?? 3] ?? '';
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
                $symbol = $m[0];
            }
            if (!$symbol) {
                $symbol = 'CASH';
            }

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

            $title = "{$eventType}: {$symbol} (" . ($amount >= 0 ? '+' : '') . '$' . number_format($amount, 2) . ')';

            $rowAccount = $row[$headerMap['ACCOUNT'] ?? -1] ?? $detectedAccount;
            if (!$rowAccount || $rowAccount === '') {
                $rowAccount = $defaultAccount;
            }

            try {
                $inserted = $this->connection->executeStatement('
                    INSERT INTO portfolio_events 
                    (event_date, event_type, provider, account_number, symbol, impact_amount, quantity, price, fees, action, impact_pct, cost_basis, realized_gain, est_tax, title, description, created_at)
                    VALUES (:date, :type, "Schwab", :accNum, :symbol, :amount, :qty, :price, :fees, :action, 0.0, 0.0, 0.0, 0.0, :title, :desc, :createdAt)
                    ON CONFLICT(event_date, symbol, title, account_number) DO UPDATE SET
                        quantity = EXCLUDED.quantity,
                        price = EXCLUDED.price,
                        fees = EXCLUDED.fees,
                        action = EXCLUDED.action
                ', [
                    'date'      => $date,
                    'type'      => $eventType,
                    'accNum'    => $rowAccount,
                    'symbol'    => $symbol,
                    'amount'    => $amount,
                    'qty'       => $qty,
                    'price'     => $price,
                    'fees'      => $fees,
                    'action'    => $action,
                    'title'     => $title,
                    'desc'      => $desc !== '' ? $desc : $action,
                    'createdAt' => $now,
                ]);

                if ($inserted > 0) {
                    $importedCount++;
                } else {
                    $skippedCount++;
                }
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
}
