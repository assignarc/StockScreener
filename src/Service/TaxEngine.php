<?php

namespace App\Service;

/**
 * TaxEngine
 *
 * Provides comprehensive tax accounting, strictly account-isolated FIFO lot matching,
 * capital gain/loss realization, two-sided option accounting (long & short contracts),
 * dividend & interest income classification, wash sale detection (IRC § 1091),
 * and statutory IRS Schedule D / Form 8949 cross-netting across taxable and retirement accounts.
 */
class TaxEngine
{
    /**
     * Standard short-term capital gains tax rate (default 20% federal bracket).
     */
    public const DEFAULT_SHORT_TERM_RATE = 0.20;

    /**
     * Standard long-term capital gains tax rate (default 15% preferential rate).
     */
    public const DEFAULT_LONG_TERM_RATE = 0.15;

    /**
     * Statutory annual capital loss deduction limit against ordinary income (IRC § 1211(b)).
     */
    public const CAPITAL_LOSS_LIMIT = 3000.0;

    /**
     * Statutory annual capital loss deduction limit for Married Filing Separately.
     */
    public const CAPITAL_LOSS_LIMIT_MFS = 1500.0;

    /**
     * Normalizes any option symbol (Schwab human-readable or OCC format) into a canonical OCC format.
     * e.g., 'INTC 09/18/2026 95.00 P' -> 'INTC 260918P00095000'
     *
     * @param string $s Raw option symbol string.
     * @return string Canonical OCC formatted option symbol.
     */
    public static function normalizeOptionSymbol(string $s): string
    {
        $clean = strtoupper(trim(preg_replace('/\s+/', ' ', $s)));
        
        // Match Human-Readable Schwab format: ROOT MM/DD/YYYY STRIKE C/P
        if (preg_match('/^([A-Z0-9]+)\s+(\d{1,2})\/(\d{1,2})\/(20\d{2}|\d{2})\s+([\d\.]+)\s+([CP])$/i', $clean, $m)) {
            $root = $m[1];
            $mm = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $dd = str_pad($m[3], 2, '0', STR_PAD_LEFT);
            $yy = strlen($m[4]) === 4 ? substr($m[4], 2, 2) : $m[4];
            $strikeVal = (float) $m[5];
            $strike = str_pad((string)((int) round($strikeVal * 1000)), 8, '0', STR_PAD_LEFT);
            $cp = strtoupper($m[6]);
            return "{$root} {$yy}{$mm}{$dd}{$cp}{$strike}";
        }
        
        // Match OCC format: ROOT YYMMDD C/P STRIKE (e.g. INTC  260918P00095000 or INTC 260918P00095000)
        if (preg_match('/^([A-Z0-9]+)\s*(\d{2})(\d{2})(\d{2})([CP])(\d{8})$/i', $clean, $m)) {
            $root = $m[1];
            $yy = $m[2];
            $mm = $m[3];
            $dd = $m[4];
            $cp = strtoupper($m[5]);
            $strike = $m[6];
            return "{$root} {$yy}{$mm}{$dd}{$cp}{$strike}";
        }
        
        return $clean;
    }

    /**
     * Formats a canonical OCC option symbol into clean, human-readable format.
     * e.g., 'INTC 260918P00095000' -> 'INTC 09/18/2026 95.00 P'
     *
     * @param string $s OCC option symbol string.
     * @return string Human-readable option string.
     */
    public static function formatOptionReadable(string $s): string
    {
        $clean = strtoupper(trim(preg_replace('/\s+/', ' ', $s)));
        if (preg_match('/^([A-Z0-9]+)\s*(\d{2})(\d{2})(\d{2})([CP])(\d{8})$/i', $clean, $m)) {
            $root = $m[1];
            $yy = $m[2];
            $mm = $m[3];
            $dd = $m[4];
            $cp = strtoupper($m[5]);
            $strike = ((float) intval($m[6])) / 1000.0;
            $strikeStr = number_format($strike, 2, '.', '');
            return "{$root} {$mm}/{$dd}/20{$yy} {$strikeStr} {$cp}";
        }
        return $s;
    }

    /**
     * Normalizes any equity ticker or option symbol into a canonical key.
     * e.g., 'INTC 09/18/2026 95.00 P' -> 'INTC 260918P00095000'
     * e.g., 'BRK.B' or 'BRK/B' -> 'BRKB'
     * e.g., 'MOG.A' or 'MOG/A' -> 'MOGA'
     *
     * @param string $s Raw symbol or ticker.
     * @return string Normalized ticker or canonical option symbol.
     */
    public static function normalizeSymbol(string $s): string
    {
        $clean = strtoupper(trim(preg_replace('/\s+/', ' ', $s)));
        $optNorm = self::normalizeOptionSymbol($clean);
        if ($optNorm !== $clean) {
            return $optNorm;
        }

        // Equity share classes (e.g. BRK.B, BRK/B, BF.B, MOG.A)
        if (preg_match('/^[A-Z]{1,5}[\.\/][A-Z]$/', $clean)) {
            return str_replace(['.', '/'], '', $clean);
        }

        return $clean;
    }

    /**
     * Determines whether a symbol or description represents an option contract.
     *
     * @param string $symbol Ticker or symbol string.
     * @param string $desc   Optional transaction description.
     * @return bool True if symbol/description is an option contract, false otherwise.
     */
    public static function isOptionSymbol(string $symbol, string $desc = ''): bool
    {
        $upperSym = strtoupper(trim($symbol));
        $upperDesc = strtoupper($desc);

        // Guard against fixed income bond calls, CD redemptions, and interest
        if (str_contains($upperDesc, '**CALLED**') || str_contains($upperDesc, 'BOND INTEREST') || str_contains($upperDesc, 'CD INTEREST') || str_contains($upperDesc, '%CD')) {
            return false;
        }

        return (
            preg_match('/^[A-Z0-9]+\s*\d{6}[CP]\d{8}$/i', $upperSym)
            || preg_match('/^[A-Z0-9]+\s+\d{1,2}\/\d{1,2}\/(?:20\d{2}|\d{2})\s+[\d\.]+\s+[CP]$/i', $upperSym)
            || preg_match('/\b\d{1,2}\/\d{1,2}\/\d{2,4}\s+\d+(\.\d+)?\s+[CP]\b/i', $upperSym)
            || preg_match('/\b(CALL|PUT)\b/i', $upperDesc)
        );
    }

    /**
     * Determines whether an account identifier, nickname, or type represents a tax-advantaged retirement account.
     * Covers Traditional IRA, Roth IRA, Rollover IRA, PCRA, 401(k), 403(b), 457, SIP, HSA, SEP, and SIMPLE.
     *
     * @param string $name Account name or nickname.
     * @param string $type Account type reported by broker.
     * @return bool True if tax-advantaged retirement account, false if taxable brokerage.
     */
    public static function isRetirementAccount(string $name, string $type = ''): bool
    {
        $upperName = strtoupper(trim($name));
        $upperType = strtoupper(trim($type));

        $retirementKeywords = [
            'IRA', 'ROTH', 'PCRA', 'SIP', 'ROLLOVER', '401K', '401(K)', 
            '403B', '403(B)', '457', 'HSA', 'SEP', 'SIMPLE', 'PENSION', 
            'ANNUITY', 'RETIREMENT'
        ];

        foreach ($retirementKeywords as $kw) {
            if (str_contains($upperName, $kw) || str_contains($upperType, $kw)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves human-readable labels for special instruments like Bank CDs.
     *
     * @param string      $symbol   Asset symbol.
     * @param string|null $itemDesc Asset description.
     * @return string Resolved symbol label.
     */
    private function resolveSymbol(string $symbol, ?string $itemDesc = null): string
    {
        $clean = strtoupper(trim($symbol));
        if ($itemDesc && stripos($itemDesc, 'CD') !== false) {
            return $clean . ' (Bank CD)';
        }
        return $clean;
    }

    /**
     * Calculates tax realizations by processing transaction history against the current portfolio.
     * Strictly enforces account isolation (IRC § 1012), two-sided option matching (IRC § 1234),
     * and wash sale detection (IRC § 1091).
     *
     * @param array $history          Array of transaction records.
     * @param array $currentPortfolio Aggregated portfolio data.
     * @param array $options          Calculation options (e.g. detectWashSales => true).
     * @return array List of calculated tax realization records.
     */
    public function calculateTaxRealizations(array $history, array $currentPortfolio, array $options = []): array
    {
        $detectWashSales = $options['detectWashSales'] ?? true;

        // 1. Build Account Nickname, Tax Status, and Position Cost Basis Maps
        $currentCostBasisMap = [];
        $accountTaxStatusMap = []; // [accKey] => 'TAXABLE' | 'RETIREMENT_IRA'
        $accountNicknameMap  = []; // [accNum/masked/nick] => 'V-Brokerage'

        foreach ($currentPortfolio['accounts'] ?? [] as $acc) {
            $accNum = (string) ($acc['accountNumber'] ?? $acc['account_num'] ?? '');
            $nick = (string) ($acc['nickname'] ?? '');
            $last4 = substr($accNum, -4);
            $maskedNum = $accNum !== '' ? ('***' . $last4) : '';
            $accType = (string) ($acc['type'] ?? '');
            
            $isRetirement = self::isRetirementAccount($nick, $accType) || self::isRetirementAccount($accNum, $accType);
            $taxStatus = $isRetirement ? 'RETIREMENT_IRA' : 'TAXABLE';

            $canonicalNick = $nick !== '' ? $nick : ($maskedNum ?: $accNum);

            if ($nick !== '') {
                $accountNicknameMap[$nick] = $nick;
                $accountNicknameMap[strtoupper($nick)] = $nick;
                if ($accNum) $accountNicknameMap[$accNum] = $nick;
                if ($maskedNum) $accountNicknameMap[$maskedNum] = $nick;
                if ($last4) $accountNicknameMap[$last4] = $nick;
            }

            if ($accNum) $accountTaxStatusMap[$accNum] = $taxStatus;
            if ($maskedNum) $accountTaxStatusMap[$maskedNum] = $taxStatus;
            if ($nick) $accountTaxStatusMap[$nick] = $taxStatus;
            if ($canonicalNick) $accountTaxStatusMap[$canonicalNick] = $taxStatus;

            foreach ($acc['positions'] ?? [] as $pos) {
                $sym = $pos['symbol'] ?? '';
                $cost = (float) ($pos['cost_basis'] ?? $pos['averagePrice'] ?? 0.0);
                if ($sym && $cost > 0) {
                    $canonSym = self::normalizeSymbol($sym);
                    if ($accNum) $currentCostBasisMap[$accNum][$canonSym] = $cost;
                    if ($maskedNum) $currentCostBasisMap[$maskedNum][$canonSym] = $cost;
                    if ($canonicalNick) $currentCostBasisMap[$canonicalNick][$canonSym] = $cost;
                }
            }
        }

        // 2. Sort history chronologically (oldest first) to track FIFO buys/sells
        $chronoHistory = $history;
        usort($chronoHistory, function ($a, $b) {
            return strcmp($a['date'] ?? '', $b['date'] ?? '');
        });

        // Deduplicate transactions in history feed (e.g. if DB and API or CSV export overlap)
        $seenTx = [];
        $dedupedHistory = [];
        foreach ($chronoHistory as $tx) {
            $rawAcc = (string) ($tx['account_number'] ?? '');
            $accNick = (string) ($tx['account_nickname'] ?? '');
            $date = $tx['date'] ?? '';
            $netAmount = round((float) ($tx['amount'] ?? 0.0), 2);
            $type = strtoupper($tx['type'] ?? '');
            $sym = strtoupper(trim($tx['symbol'] ?? ''));
            $canonicalSym = self::normalizeSymbol($sym);
            
            // Normalize account key so API (***3261) and DB (V-Brokerage) deduplicate reliably
            $resolvedAcc = $accNick !== '' 
                ? ($accountNicknameMap[$accNick] ?? $accNick) 
                : ($accountNicknameMap[$rawAcc] ?? ($rawAcc !== '' ? $rawAcc : 'PRIMARY'));

            $txKey = "{$date}_{$canonicalSym}_{$netAmount}_{$type}_{$resolvedAcc}";
            if (isset($seenTx[$txKey])) {
                continue;
            }
            $seenTx[$txKey] = true;
            $dedupedHistory[] = $tx;
        }

        // Strictly isolated FIFO queues per account:
        // [account][symbol] => FIFO queue of buys
        $buyQueues = [];
        // [account][symbol] => FIFO queue of purchased long options
        $longOptionQueues = [];
        // [account][symbol] => FIFO queue of written short options
        $shortOptionQueues = [];

        $realizations = [];
        // Track recent purchases for IRC § 1091 Wash Sale detection: [account][symbol][] = ['date', 'qty', 'cost']
        $purchasesForWashSale = [];

        foreach ($dedupedHistory as $tx) {
            $rawAcc = (string) ($tx['account_number'] ?? '');
            $accNick = (string) ($tx['account_nickname'] ?? '');
            $maskedKey = $rawAcc !== '' ? ('***' . substr($rawAcc, -4)) : '';

            // Resolve to canonical account key
            $accKey = $accNick !== '' 
                ? ($accountNicknameMap[$accNick] ?? $accNick) 
                : ($accountNicknameMap[$rawAcc] ?? $accountNicknameMap[$maskedKey] ?? ($rawAcc !== '' ? $rawAcc : 'PRIMARY'));

            $taxStatus = $accountTaxStatusMap[$accKey] 
                ?? $accountTaxStatusMap[$rawAcc] 
                ?? $accountTaxStatusMap[$maskedKey] 
                ?? (self::isRetirementAccount($accKey) || self::isRetirementAccount($rawAcc) ? 'RETIREMENT_IRA' : 'TAXABLE');

            $date = $tx['date'] ?? '';
            $netAmount = (float) ($tx['amount'] ?? 0.0);
            $type = strtoupper($tx['type'] ?? '');
            $action = strtoupper($tx['action'] ?? '');

            // Non-taxable transfer events guard
            if (
                in_array($type, ['JOURNAL', 'TRANSFER', 'SECURITY TRANSFER', 'SYSTEM TRANSFER'])
                || in_array($action, ['JOURNAL', 'TRANSFER', 'SECURITY TRANSFER', 'SYSTEM TRANSFER', 'WIRE SENT', 'MONEYLINK TRANSFER'])
            ) {
                continue;
            }

            $item = null;
            foreach ($tx['transfer_items'] ?? [] as $ti) {
                $aType = strtoupper($ti['asset_type'] ?? '');
                if ($aType !== 'CURRENCY') {
                    $item = $ti;
                    break;
                }
            }
            if (!$item && !empty($tx['transfer_items'])) {
                $item = $tx['transfer_items'][0];
            }

            $symbol = $tx['symbol'] ?? '';
            if ((!$symbol || $symbol === 'CURRENCY_USD') && $item && !empty($item['symbol']) && $item['symbol'] !== 'CURRENCY_USD') {
                $symbol = $item['symbol'];
            }

            if (!$symbol || $symbol === 'CURRENCY_USD') {
                continue;
            }

            $upperSym = strtoupper(trim($symbol));
            $desc = $tx['description'] ?? '';
            $upperDesc = strtoupper($desc);

            // Guard 1: Filter cash transfers, wires, interest journals
            if (
                in_array($upperSym, ['CASH', 'CURRENCY_USD', 'WIRED', 'FRM', 'BANK', 'B', 'CURRENCY', 'SEC', 'ADR'])
                || str_contains($upperDesc, 'JOURNAL')
                || str_contains($upperDesc, 'WIRED FUNDS')
                || str_contains($upperDesc, 'TRANSFER FUNDS')
                || str_contains($upperDesc, 'TRF FUNDS')
                || str_contains($upperDesc, 'BANK INT')
                || str_contains($upperDesc, 'SCHWAB1 INT')
                || str_contains($upperDesc, 'SEC FEE')
            ) {
                continue;
            }

            // Guard 2: Filter CD maturities / redemptions with $0 proceeds (return of principal)
            if (
                ($netAmount == 0.0 || $netAmount > 0)
                && (str_contains($upperDesc, '**MATURED**') || str_contains($upperDesc, 'FDIC INS DUE') || str_contains($upperDesc, '%CD'))
            ) {
                continue;
            }

            // Guard 3: Filter corporate action adjustments / merger placeholders
            if (
                $netAmount == 0.0
                && (
                    in_array($action, ['CASH MERGER ADJ', 'STOCK MERGER', 'CASH/STOCK MERGER', 'MANDATORY REORG EXC', 'REVERSE SPLIT', 'FINAL CASH LIQUID ADJ', 'ADR MGMT FEE ADJ', 'REDEMPTION ADJ', 'CXL REDEMPTION ADJ', 'STOCK PLAN ACTIVITY'])
                    || str_contains($upperDesc, 'MANDATORY MERGER') 
                    || str_contains($upperDesc, 'REVERSE SPLIT') 
                    || str_contains($upperDesc, 'NAME CHANGE') 
                    || str_contains($upperDesc, 'ESCROW PENDING') 
                    || str_contains($upperDesc, 'SYSTEM TRANSFER')
                    || str_contains($upperDesc, 'MERGER')
                )
            ) {
                continue;
            }

            $qty = $item ? (float) ($item['amount'] ?? 0.0) : 0.0;
            $price = $item ? (float) ($item['price'] ?? 0.0) : 0.0;
            $assetType = $item ? strtoupper($item['asset_type'] ?? '') : '';

            $isExpiration = ($type === 'EXPIRATION' 
                || stripos($desc, 'EXPIRATION') !== false 
                || stripos($desc, 'EXPIRED') !== false 
                || stripos($desc, 'REMOVAL OF OPTION') !== false
                || stripos($desc, 'ASSIGNED') !== false
                || stripos($desc, 'ASSIGNMENT') !== false
                || stripos($desc, 'EXERCISE') !== false
            );

            $isOption = (
                $assetType === 'OPTION'
                || $type === 'OPTION'
                || self::isOptionSymbol($symbol, $desc)
            );

            $isTrade = ($type === 'TRADE' || $type === 'OPTION') && !$isExpiration;

            // Normalized canonical symbol for options vs equities
            $canonicalSymbol = self::normalizeSymbol($symbol);
            $readableSymbol = $isOption ? self::formatOptionReadable($canonicalSymbol) : $this->resolveSymbol($symbol, $desc);

            if ($isTrade) {
                if ($isOption) {
                    // TWO-SIDED OPTION ACCOUNTING (IRC § 1234)
                    $positionEffect = strtoupper($item['position_effect'] ?? '');
                    
                    // Determine option intent: Long (buy to open, sell to close) vs Short (sell to open, buy to close)
                    $isBuyToOpen = ($netAmount < 0 && ($positionEffect === 'OPENING' || str_contains($action, 'BUY_TO_OPEN') || (empty($shortOptionQueues[$accKey][$canonicalSymbol]) && empty($positionEffect))));
                    $isSellToOpen = ($netAmount > 0 && ($positionEffect === 'OPENING' || str_contains($action, 'SELL_TO_OPEN') || (empty($longOptionQueues[$accKey][$canonicalSymbol]) && empty($positionEffect))));
                    $isBuyToClose = ($netAmount < 0 && !$isBuyToOpen);
                    $isSellToClose = ($netAmount > 0 && !$isSellToOpen);

                    if ($isBuyToOpen) {
                        // 1. Long Option Purchase: Record cost basis into long option queue
                        $contractQty = abs($qty) > 0 ? abs($qty) : ($price > 0 ? (abs($netAmount) / ($price * 100)) : 1.0);
                        $optionCost = abs($netAmount);
                        $longOptionQueues[$accKey][$canonicalSymbol][] = [
                            'date' => $date,
                            'qty' => $contractQty,
                            'cost' => $optionCost,
                            'price' => $price,
                            'fees' => (float) ($tx['fees'] ?? 0.0),
                        ];
                        // Also record for wash sale tracking
                        $purchasesForWashSale[$accKey][$canonicalSymbol][] = [
                            'date' => $date,
                            'qty' => $contractQty,
                            'cost' => $optionCost,
                        ];
                    } elseif ($isSellToOpen) {
                        // 2. Short Option Write (Covered Call / Cash-Secured Put): Premium received is deferred until close/expiration
                        $contractQty = abs($qty) > 0 ? abs($qty) : ($price > 0 ? (abs($netAmount) / ($price * 100)) : 1.0);
                        $premium = abs($netAmount);
                        $shortOptionQueues[$accKey][$canonicalSymbol][] = [
                            'date' => $date,
                            'qty' => $contractQty,
                            'premium' => $premium,
                            'price' => $price,
                            'fees' => (float) ($tx['fees'] ?? 0.0),
                        ];
                    } elseif ($isSellToClose) {
                        // 3. Long Option Closing Sale: Matches against purchased lots in long option queue
                        $closeQty = abs($qty) > 0 ? abs($qty) : 1.0;
                        $remainingCloseQty = $closeQty;
                        $proceeds = abs($netAmount);
                        $totalCostBasis = 0.0;
                        $buyDate = 'N/A';
                        $oldestBuyDateObj = null;

                        if (!empty($longOptionQueues[$accKey][$canonicalSymbol])) {
                            while ($remainingCloseQty > 0 && !empty($longOptionQueues[$accKey][$canonicalSymbol])) {
                                $oldestLong = &$longOptionQueues[$accKey][$canonicalSymbol][0];
                                $matchQty = min($remainingCloseQty, $oldestLong['qty']);
                                $ratio = $oldestLong['qty'] > 0 ? ($matchQty / $oldestLong['qty']) : 1.0;

                                $allocatedCost = $oldestLong['cost'] * $ratio;
                                $allocatedFees = ($oldestLong['fees'] ?? 0.0) * $ratio;
                                $totalCostBasis += $allocatedCost + $allocatedFees;

                                if ($buyDate === 'N/A') {
                                    $buyDate = $oldestLong['date'];
                                    $oldestBuyDateObj = new \DateTimeImmutable($buyDate);
                                }

                                $oldestLong['qty'] -= $matchQty;
                                $oldestLong['cost'] -= $allocatedCost;
                                $remainingCloseQty -= $matchQty;

                                if ($oldestLong['qty'] <= 0) {
                                    array_shift($longOptionQueues[$accKey][$canonicalSymbol]);
                                }
                            }
                        }

                        // If un-matched in window, fallback to sale proceeds ($0 gain/loss) rather than assuming 0 cost basis
                        if ($totalCostBasis == 0.0) {
                            $totalCostBasis = $proceeds;
                            $buyDate = 'Pre-existing Long';
                        }

                        $realizedGain = $proceeds - $totalCostBasis;
                        $term = 'SHORT_TERM';
                        if ($oldestBuyDateObj !== null) {
                            $diff = (new \DateTimeImmutable($date))->diff($oldestBuyDateObj);
                            if ($diff->y >= 1) $term = 'LONG_TERM';
                        }

                        $effectiveRate = ($taxStatus === 'RETIREMENT_IRA') ? 0.0 : ($term === 'LONG_TERM' ? self::DEFAULT_LONG_TERM_RATE : self::DEFAULT_SHORT_TERM_RATE);
                        $effectiveTax = ($taxStatus === 'RETIREMENT_IRA' || $realizedGain <= 0) ? 0.0 : ($realizedGain * $effectiveRate);

                        $realizations[] = [
                            'rawSymbol' => $canonicalSymbol,
                            'symbol' => $readableSymbol,
                            'description' => $desc ?: "Sell to Close Long Option ({$readableSymbol})",
                            'assetType' => 'OPTION',
                            'account' => $accKey,
                            'taxStatus' => $taxStatus,
                            'sellDate' => $date,
                            'buyDate' => $buyDate,
                            'qty' => $closeQty,
                            'costBasis' => $totalCostBasis,
                            'proceeds' => $proceeds,
                            'realizedGain' => $realizedGain,
                            'term' => $term,
                            'taxRate' => $effectiveRate,
                            'estTax' => $effectiveTax,
                            'positionSide' => 'LONG_OPTION',
                        ];
                    } elseif ($isBuyToClose) {
                        // 4. Short Option Closing Buyback: Matches against written lots in short option queue
                        $closeQty = abs($qty) > 0 ? abs($qty) : 1.0;
                        $remainingCloseQty = $closeQty;
                        $costToBuyBack = abs($netAmount);
                        $initialPremiumReceived = 0.0;
                        $writeDate = 'N/A';

                        if (!empty($shortOptionQueues[$accKey][$canonicalSymbol])) {
                            while ($remainingCloseQty > 0 && !empty($shortOptionQueues[$accKey][$canonicalSymbol])) {
                                $oldestWrite = &$shortOptionQueues[$accKey][$canonicalSymbol][0];
                                $matchQty = min($remainingCloseQty, $oldestWrite['qty']);
                                $ratio = $oldestWrite['qty'] > 0 ? ($matchQty / $oldestWrite['qty']) : 1.0;

                                $allocatedPremium = $oldestWrite['premium'] * $ratio;
                                $allocatedFees = ($oldestWrite['fees'] ?? 0.0) * $ratio;
                                $initialPremiumReceived += $allocatedPremium - $allocatedFees;

                                if ($writeDate === 'N/A') {
                                    $writeDate = $oldestWrite['date'];
                                }

                                $oldestWrite['qty'] -= $matchQty;
                                $oldestWrite['premium'] -= $allocatedPremium;
                                $remainingCloseQty -= $matchQty;

                                if ($oldestWrite['qty'] <= 0) {
                                    array_shift($shortOptionQueues[$accKey][$canonicalSymbol]);
                                }
                            }
                        }

                        // If un-matched write in window, fallback to buyback cost ($0 gain/loss)
                        if ($initialPremiumReceived == 0.0) {
                            $initialPremiumReceived = $costToBuyBack;
                            $writeDate = 'Pre-existing Short';
                        }

                        // Gain on short option = Initial Premium Received - Buyback Cost
                        $realizedGain = $initialPremiumReceived - $costToBuyBack;
                        // IRC § 1234(b)(1): Closing transactions on written options are always Short-Term
                        $term = 'SHORT_TERM';
                        $effectiveRate = ($taxStatus === 'RETIREMENT_IRA') ? 0.0 : self::DEFAULT_SHORT_TERM_RATE;
                        $effectiveTax = ($taxStatus === 'RETIREMENT_IRA' || $realizedGain <= 0) ? 0.0 : ($realizedGain * $effectiveRate);

                        $realizations[] = [
                            'rawSymbol' => $canonicalSymbol,
                            'symbol' => $readableSymbol,
                            'description' => $desc ?: "Buy to Close Short Option ({$readableSymbol})",
                            'assetType' => 'OPTION',
                            'account' => $accKey,
                            'taxStatus' => $taxStatus,
                            'sellDate' => $date,
                            'buyDate' => $writeDate,
                            'qty' => $closeQty,
                            'costBasis' => $costToBuyBack,
                            'proceeds' => $initialPremiumReceived,
                            'realizedGain' => $realizedGain,
                            'term' => $term,
                            'taxRate' => $effectiveRate,
                            'estTax' => $effectiveTax,
                            'positionSide' => 'SHORT_OPTION',
                        ];
                    }
                } else {
                    // Equity/Bond/CD trades
                    $isBuy = ($qty > 0 || $action === 'BUY' || in_array($action, ['CD DEPOSIT FUNDS', 'CD DEPOSIT ADJ', 'REINVEST SHARES']) || ($netAmount < 0 && $action !== 'SELL'));

                    if ($isBuy) {
                        // BUY TO OPEN - Push into isolated account queue
                        $buyCost = abs($netAmount);
                        if ($buyCost == 0.0 && $item && isset($item['cost'])) {
                            $buyCost = abs((float) $item['cost']);
                        }

                        $buyQty = $qty > 0 ? $qty : ($price > 0 ? ($buyCost / $price) : $buyCost);

                        $buyRecord = [
                            'date' => $date,
                            'qty' => $buyQty,
                            'price' => $price,
                            'cost' => $buyCost,
                            'fees' => (float) ($tx['fees'] ?? 0.0),
                        ];

                        $buyQueues[$accKey][$canonicalSymbol][] = $buyRecord;
                        // Record for wash sale tracking
                        $purchasesForWashSale[$accKey][$canonicalSymbol][] = [
                            'date' => $date,
                            'qty' => $buyQty,
                            'cost' => $buyCost,
                        ];
                    } else {
                        // SELL TO CLOSE (Strictly within account queue)
                        if ($netAmount <= 0 && $qty == 0) {
                            continue;
                        }

                        $remainingSellQty = abs($qty);
                        if ($remainingSellQty == 0 && $price > 0) {
                            $remainingSellQty = abs($netAmount) / $price;
                        } elseif ($remainingSellQty == 0) {
                            $remainingSellQty = abs($netAmount);
                        }

                        $totalCostBasis = 0.0;
                        $buyDate = '';
                        $oldestBuyDateObj = null;

                        // 1. First priority: Match against isolated FIFO buy queue for this specific account
                        if (!empty($buyQueues[$accKey][$canonicalSymbol])) {
                            while ($remainingSellQty > 0 && !empty($buyQueues[$accKey][$canonicalSymbol])) {
                                $oldestBuy = &$buyQueues[$accKey][$canonicalSymbol][0];
                                $matchQty = min($remainingSellQty, $oldestBuy['qty']);
                                $ratio = $oldestBuy['qty'] > 0 ? ($matchQty / $oldestBuy['qty']) : 1.0;
                                
                                $allocatedCost = $oldestBuy['cost'] * $ratio;
                                $allocatedFees = $oldestBuy['fees'] * $ratio;
                                $totalCostBasis += $allocatedCost + $allocatedFees;

                                $bDate = $oldestBuy['date'];
                                $bDateObj = new \DateTimeImmutable($bDate);
                                if ($oldestBuyDateObj === null || $bDateObj < $oldestBuyDateObj) {
                                    $oldestBuyDateObj = $bDateObj;
                                    $buyDate = $bDate;
                                }

                                $oldestBuy['cost'] -= $allocatedCost;
                                $oldestBuy['fees'] -= $allocatedFees;
                                $oldestBuy['qty'] -= $matchQty;
                                $remainingSellQty -= $matchQty;

                                if ($oldestBuy['qty'] <= 0) {
                                    array_shift($buyQueues[$accKey][$canonicalSymbol]);
                                }
                            }
                        }

                        // 2. Second priority: Broker reported transaction cost on the trade item
                        if ($remainingSellQty > 0) {
                            $txItemCost = (float) ($item['cost'] ?? $item['cost_basis'] ?? $item['costBasis'] ?? $tx['cost_basis'] ?? $tx['costBasis'] ?? 0.0);
                            
                            $impliedPerShareCost = $remainingSellQty > 0 ? ($txItemCost / $remainingSellQty) : 0;
                            $salePerSharePrice = $remainingSellQty > 0 && $netAmount > 0 ? ($netAmount / $remainingSellQty) : 0;
                            
                            if ($salePerSharePrice > 0 && $impliedPerShareCost > ($salePerSharePrice * 3.0)) {
                                $fallbackAvg = $currentCostBasisMap[$accKey][$canonicalSymbol] 
                                    ?? $currentCostBasisMap[$rawAcc][$canonicalSymbol] 
                                    ?? $currentCostBasisMap[$maskedKey][$canonicalSymbol] 
                                    ?? 0.0;
                                
                                if ($fallbackAvg > 0 && $fallbackAvg < ($salePerSharePrice * 3.0)) {
                                    $txItemCost = $fallbackAvg * $remainingSellQty;
                                } else {
                                    $txItemCost = $salePerSharePrice * $remainingSellQty;
                                }
                            }

                            if ($txItemCost > 0 && ($netAmount == 0.0 || $txItemCost > ($netAmount * 0.1))) {
                                $totalCostBasis += $txItemCost;
                                $remainingSellQty = 0;
                                if ($buyDate === '' && (isset($item['purchase_date']) || isset($tx['purchase_date']))) {
                                    $buyDate = $item['purchase_date'] ?? $tx['purchase_date'];
                                    $oldestBuyDateObj = new \DateTimeImmutable($buyDate);
                                }
                            }
                        }

                        // 3. Third priority: Account's current portfolio average cost basis
                        if ($remainingSellQty > 0) {
                            $fallbackAvg = $currentCostBasisMap[$accKey][$canonicalSymbol] 
                                ?? $currentCostBasisMap[$rawAcc][$canonicalSymbol] 
                                ?? $currentCostBasisMap[$maskedKey][$canonicalSymbol] 
                                ?? 0.0;

                            if ($fallbackAvg > 0) {
                                $totalCostBasis += $fallbackAvg * $remainingSellQty;
                            } else {
                                // Default to sale price (0 gain/loss) rather than assuming 0 cost basis (100% gain)
                                $totalCostBasis += max(0.0, $netAmount);
                            }
                            if ($buyDate === '') {
                                $buyDate = '2022-01-01 (Pre-existing)';
                            }
                        }

                        $proceeds = $netAmount;
                        $realizedGain = $proceeds - $totalCostBasis;

                        // Calculate holding period term
                        $term = 'SHORT_TERM';
                        $rate = self::DEFAULT_SHORT_TERM_RATE;
                        
                        if ($buyDate === '2022-01-01 (Pre-existing)') {
                            $term = 'LONG_TERM';
                            $rate = self::DEFAULT_LONG_TERM_RATE;
                        } elseif ($oldestBuyDateObj !== null) {
                            $sellDateObj = new \DateTimeImmutable($date);
                            $diff = $sellDateObj->diff($oldestBuyDateObj);
                            if ($diff->y >= 1) {
                                $term = 'LONG_TERM';
                                $rate = self::DEFAULT_LONG_TERM_RATE;
                            }
                        }

                        $itemDesc = $item['description'] ?? $desc;
                        $effectiveRate = ($taxStatus === 'RETIREMENT_IRA') ? 0.0 : $rate;
                        $effectiveTax = ($taxStatus === 'RETIREMENT_IRA' || $realizedGain <= 0) ? 0.0 : ($realizedGain * $effectiveRate);

                        $realizations[] = [
                            'rawSymbol' => $canonicalSymbol,
                            'symbol' => $readableSymbol,
                            'description' => $itemDesc,
                            'assetType' => $assetType !== '' ? $assetType : 'EQUITY',
                            'account' => $accKey,
                            'taxStatus' => $taxStatus,
                            'sellDate' => $date,
                            'buyDate' => $buyDate,
                            'qty' => abs($qty),
                            'costBasis' => $totalCostBasis,
                            'proceeds' => $proceeds,
                            'realizedGain' => $realizedGain,
                            'term' => $term,
                            'taxRate' => $effectiveRate,
                            'estTax' => $effectiveTax,
                            'positionSide' => 'EQUITY',
                        ];
                    }
                }
            } elseif ($isExpiration) {
                // If it is an option expiration / assignment event in the history stream
                $effectiveRate = ($taxStatus === 'RETIREMENT_IRA') ? 0.0 : self::DEFAULT_SHORT_TERM_RATE;

                // 1. Process written short options
                if (!empty($shortOptionQueues[$accKey][$canonicalSymbol])) {
                    foreach ($shortOptionQueues[$accKey][$canonicalSymbol] as $oldestWrite) {
                        if (($oldestWrite['qty'] ?? 0) > 0) {
                            $gain = $oldestWrite['premium'];
                            $effectiveTax = ($taxStatus === 'RETIREMENT_IRA' || $gain <= 0) ? 0.0 : ($gain * $effectiveRate);

                            $realizations[] = [
                                'rawSymbol' => $canonicalSymbol,
                                'symbol' => $readableSymbol,
                                'description' => $desc ?: "Option Expiration ({$readableSymbol})",
                                'assetType' => 'OPTION',
                                'account' => $accKey,
                                'taxStatus' => $taxStatus,
                                'sellDate' => $date,
                                'buyDate' => $oldestWrite['date'],
                                'qty' => $oldestWrite['qty'],
                                'costBasis' => 0.0,
                                'proceeds' => $oldestWrite['premium'],
                                'realizedGain' => $gain,
                                'term' => 'SHORT_TERM',
                                'taxRate' => $effectiveRate,
                                'estTax' => $effectiveTax,
                                'positionSide' => 'SHORT_OPTION',
                            ];
                        }
                    }
                    $shortOptionQueues[$accKey][$canonicalSymbol] = [];
                }

                // 2. Process purchased long options that expired worthless (100% loss)
                if (!empty($longOptionQueues[$accKey][$canonicalSymbol])) {
                    foreach ($longOptionQueues[$accKey][$canonicalSymbol] as $oldestLong) {
                        if (($oldestLong['qty'] ?? 0) > 0) {
                            $loss = -$oldestLong['cost'];

                            $realizations[] = [
                                'rawSymbol' => $canonicalSymbol,
                                'symbol' => $readableSymbol,
                                'description' => $desc ?: "Expired Worthless Long Option ({$readableSymbol})",
                                'assetType' => 'OPTION',
                                'account' => $accKey,
                                'taxStatus' => $taxStatus,
                                'sellDate' => $date,
                                'buyDate' => $oldestLong['date'],
                                'qty' => $oldestLong['qty'],
                                'costBasis' => $oldestLong['cost'],
                                'proceeds' => 0.0,
                                'realizedGain' => $loss,
                                'term' => 'SHORT_TERM',
                                'taxRate' => $effectiveRate,
                                'estTax' => 0.0,
                                'positionSide' => 'LONG_OPTION',
                            ];
                        }
                    }
                    $longOptionQueues[$accKey][$canonicalSymbol] = [];
                }
            }
        }

        // 3. Auto-evaluate expired option contracts whose expiration date has passed today
        $today = date('Y-m-d');
        
        // A. Expired written short options (100% short-term gain)
        foreach ($shortOptionQueues as $qAccKey => $symbolQueues) {
            $taxStatus = $accountTaxStatusMap[$qAccKey] ?? (self::isRetirementAccount($qAccKey) ? 'RETIREMENT_IRA' : 'TAXABLE');
            $effectiveRate = ($taxStatus === 'RETIREMENT_IRA') ? 0.0 : self::DEFAULT_SHORT_TERM_RATE;

            foreach ($symbolQueues as $symKey => $records) {
                $canonicalSym = self::normalizeOptionSymbol($symKey);
                $readableSym = self::formatOptionReadable($canonicalSym);

                $expDate = self::extractOptionExpiration($canonicalSym);
                if ($expDate && $expDate <= $today) {
                    foreach ($records as $oldestWrite) {
                        if (($oldestWrite['qty'] ?? 0) > 0) {
                            $gain = $oldestWrite['premium'];
                            $effectiveTax = ($taxStatus === 'RETIREMENT_IRA' || $gain <= 0) ? 0.0 : ($gain * $effectiveRate);

                            $realizations[] = [
                                'rawSymbol' => $canonicalSym,
                                'symbol' => $readableSym,
                                'description' => "Expired Written Option Contract ({$readableSym})",
                                'assetType' => 'OPTION',
                                'account' => $qAccKey,
                                'taxStatus' => $taxStatus,
                                'sellDate' => $expDate,
                                'buyDate' => $oldestWrite['date'],
                                'qty' => $oldestWrite['qty'],
                                'costBasis' => 0.0,
                                'proceeds' => $oldestWrite['premium'],
                                'realizedGain' => $gain,
                                'term' => 'SHORT_TERM',
                                'taxRate' => $effectiveRate,
                                'estTax' => $effectiveTax,
                                'positionSide' => 'SHORT_OPTION',
                            ];
                        }
                    }
                }
            }
        }

        // B. Expired purchased long options (100% capital loss)
        foreach ($longOptionQueues as $qAccKey => $symbolQueues) {
            $taxStatus = $accountTaxStatusMap[$qAccKey] ?? (self::isRetirementAccount($qAccKey) ? 'RETIREMENT_IRA' : 'TAXABLE');
            $effectiveRate = ($taxStatus === 'RETIREMENT_IRA') ? 0.0 : self::DEFAULT_SHORT_TERM_RATE;

            foreach ($symbolQueues as $symKey => $records) {
                $canonicalSym = self::normalizeOptionSymbol($symKey);
                $readableSym = self::formatOptionReadable($canonicalSym);

                $expDate = self::extractOptionExpiration($canonicalSym);
                if ($expDate && $expDate <= $today) {
                    foreach ($records as $oldestLong) {
                        if (($oldestLong['qty'] ?? 0) > 0) {
                            $loss = -$oldestLong['cost'];

                            $realizations[] = [
                                'rawSymbol' => $canonicalSym,
                                'symbol' => $readableSym,
                                'description' => "Expired Worthless Long Option ({$readableSym})",
                                'assetType' => 'OPTION',
                                'account' => $qAccKey,
                                'taxStatus' => $taxStatus,
                                'sellDate' => $expDate,
                                'buyDate' => $oldestLong['date'],
                                'qty' => $oldestLong['qty'],
                                'costBasis' => $oldestLong['cost'],
                                'proceeds' => 0.0,
                                'realizedGain' => $loss,
                                'term' => 'SHORT_TERM',
                                'taxRate' => $effectiveRate,
                                'estTax' => 0.0,
                                'positionSide' => 'LONG_OPTION',
                            ];
                        }
                    }
                }
            }
        }

        // 4. Wash Sale Detection (IRC § 1091)
        if ($detectWashSales) {
            $this->applyWashSaleRules($realizations, $purchasesForWashSale);
        }

        return $realizations;
    }

    /**
     * Extracts all Dividend and Interest Income events from history.
     * Categorizes into Qualified Dividends (1099-DIV Box 1b), Ordinary Dividends (Box 1a),
     * Interest Income (1099-INT Box 1), and Foreign Tax Paid (Box 7).
     *
     * @param array $history          Raw transaction history array.
     * @param array $currentPortfolio Portfolio configuration.
     * @return array Structured list of dividend and interest records.
     */
    public function calculateIncomeRealizations(array $history, array $currentPortfolio): array
    {
        $accountTaxStatusMap = [];
        $accountNicknameMap = [];

        foreach ($currentPortfolio['accounts'] ?? [] as $acc) {
            $accNum = (string) ($acc['accountNumber'] ?? $acc['account_num'] ?? '');
            $nick = (string) ($acc['nickname'] ?? '');
            $maskedNum = $accNum !== '' ? ('***' . substr($accNum, -4)) : '';
            $accType = (string) ($acc['type'] ?? '');
            $taxStatus = (self::isRetirementAccount($nick, $accType) || self::isRetirementAccount($accNum, $accType)) ? 'RETIREMENT_IRA' : 'TAXABLE';

            if ($nick !== '') {
                $accountNicknameMap[$nick] = $nick;
                if ($accNum) $accountNicknameMap[$accNum] = $nick;
                if ($maskedNum) $accountNicknameMap[$maskedNum] = $nick;
            }
            if ($accNum) $accountTaxStatusMap[$accNum] = $taxStatus;
            if ($maskedNum) $accountTaxStatusMap[$maskedNum] = $taxStatus;
            if ($nick) $accountTaxStatusMap[$nick] = $taxStatus;
        }

        $records = [];
        foreach ($history as $tx) {
            $rawAcc = (string) ($tx['account_number'] ?? '');
            $accNick = (string) ($tx['account_nickname'] ?? '');
            $maskedKey = $rawAcc !== '' ? ('***' . substr($rawAcc, -4)) : '';
            $accKey = $accNick !== '' ? ($accountNicknameMap[$accNick] ?? $accNick) : ($accountNicknameMap[$rawAcc] ?? $accountNicknameMap[$maskedKey] ?? ($rawAcc ?: 'PRIMARY'));
            $taxStatus = $accountTaxStatusMap[$accKey] ?? (self::isRetirementAccount($accKey) ? 'RETIREMENT_IRA' : 'TAXABLE');

            $type = strtoupper($tx['type'] ?? '');
            $action = strtoupper($tx['action'] ?? '');
            $desc = $tx['description'] ?? '';
            $upperDesc = strtoupper($desc);
            $amount = (float) ($tx['amount'] ?? 0.0);
            $symbol = self::normalizeSymbol($tx['symbol'] ?? '');

            $isDiv = ($type === 'DIVIDEND' || $type === 'DIVIDEND_OR_INTEREST' || str_contains($action, 'DIV') || str_contains($upperDesc, 'DIVIDEND'));
            $isInterest = (str_contains($action, 'INTEREST') || str_contains($upperDesc, 'BANK INT') || str_contains($upperDesc, 'CD INT') || str_contains($upperDesc, 'CREDIT INT'));
            $isForeignTax = ($action === 'FOREIGN TAX PAID' || str_contains($upperDesc, 'FOREIGN TAX'));

            if (!$isDiv && !$isInterest && !$isForeignTax) {
                continue;
            }

            if ($isForeignTax) {
                $records[] = [
                    'date' => $tx['date'] ?? '',
                    'account' => $accKey,
                    'taxStatus' => $taxStatus,
                    'symbol' => $symbol,
                    'category' => 'FOREIGN_TAX_PAID',
                    'amount' => abs($amount),
                    'description' => $desc,
                ];
                continue;
            }

            $category = 'ORDINARY_DIVIDEND';
            $rate = self::DEFAULT_SHORT_TERM_RATE;

            if ($isInterest) {
                $category = 'INTEREST_INCOME';
            } elseif (str_contains($action, 'QUAL') || str_contains($upperDesc, 'QUALIFIED')) {
                $category = 'QUALIFIED_DIVIDEND';
                $rate = self::DEFAULT_LONG_TERM_RATE;
            }

            $effectiveRate = ($taxStatus === 'RETIREMENT_IRA') ? 0.0 : $rate;
            $estTax = ($taxStatus === 'RETIREMENT_IRA') ? 0.0 : (max(0.0, $amount) * $effectiveRate);

            $records[] = [
                'date' => $tx['date'] ?? '',
                'account' => $accKey,
                'taxStatus' => $taxStatus,
                'symbol' => $symbol,
                'category' => $category,
                'amount' => $amount,
                'taxRate' => $effectiveRate,
                'estTax' => $estTax,
                'description' => $desc,
            ];
        }

        return $records;
    }

    /**
     * Applies IRC § 1091 Wash Sale disallowance rules to taxable trade realizations.
     * If an identical or substantially identical security is repurchased within 30 days
     * before or after a loss sale, the loss is disallowed and added to the replacement cost basis.
     *
     * @param array &$realizations         Realization records to audit and adjust.
     * @param array $purchasesForWashSale Chronological record of all purchases.
     */
    private function applyWashSaleRules(array &$realizations, array $purchasesForWashSale): void
    {
        foreach ($realizations as &$r) {
            // Wash sales only trigger on taxable losses
            if (($r['taxStatus'] ?? '') !== 'TAXABLE' || ($r['realizedGain'] ?? 0.0) >= 0.0) {
                $r['isWashSale'] = false;
                $r['disallowedLoss'] = 0.0;
                $r['adjustedGain'] = $r['realizedGain'];
                continue;
            }

            $acc = $r['account'];
            $sym = $r['rawSymbol'] ?? self::normalizeSymbol($r['symbol'] ?? '');
            $sellDate = $r['sellDate'] ?? '';
            $sellDateObj = new \DateTimeImmutable($sellDate);
            $lossAmount = abs($r['realizedGain']);

            $isWash = false;
            $matchedDisallowed = 0.0;

            // Look for purchases in the same account within [-30 days, +30 days] of sell date
            if (!empty($purchasesForWashSale[$acc][$sym])) {
                foreach ($purchasesForWashSale[$acc][$sym] as $p) {
                    $buyDateObj = new \DateTimeImmutable($p['date']);
                    $interval = (int) $sellDateObj->diff($buyDateObj)->format('%r%a');

                    // If purchased within 30 days before or after, and not the identical buy trade
                    if ($interval >= -30 && $interval <= 30 && $p['date'] !== ($r['buyDate'] ?? '')) {
                        $isWash = true;
                        $matchedDisallowed = $lossAmount; // Disallow loss
                        break;
                    }
                }
            }

            $r['isWashSale'] = $isWash;
            $r['disallowedLoss'] = $matchedDisallowed;
            $r['adjustedGain'] = $isWash ? 0.0 : $r['realizedGain'];
            // If wash sale, estTax on the trade is zero since loss cannot offset gains
            if ($isWash) {
                $r['estTax'] = 0.0;
            }
        }
        unset($r);
    }

    /**
     * Executes the statutory IRS Schedule D / Form 8949 netting algorithm.
     * Computes Net ST, Net LT, cross-netting, prior-year loss carryforwards,
     * $3,000 annual ordinary loss limitation, dividend/interest tax, and final tax liability.
     *
     * @param array       $realizations           List of trade realization records.
     * @param array       $incomeRecords          List of dividend and interest income records.
     * @param array       $priorYearCarryforwards Prior year capital loss carryforwards ['shortTerm' => float, 'longTerm' => float].
     * @param array|null  $rates                  Custom tax rates ['stRate' => float, 'ltRate' => float].
     * @param string|null $filterYear             Optional tax year filter (e.g. '2026'). If null, aggregates across all visible.
     * @return array Detailed tax liability and netting breakdown.
     */
    public function calculateTaxLiability(
        array $realizations,
        array $incomeRecords = [],
        array $priorYearCarryforwards = [],
        ?array $rates = null,
        ?string $filterYear = null
    ): array {
        $stRate = (float) ($rates['stRate'] ?? self::DEFAULT_SHORT_TERM_RATE);
        $ltRate = (float) ($rates['ltRate'] ?? self::DEFAULT_LONG_TERM_RATE);

        $taxableSTGains = 0.0;
        $taxableSTLosses = 0.0;
        $taxableLTGains = 0.0;
        $taxableLTLosses = 0.0;

        $retireSTGains = 0.0;
        $retireSTLosses = 0.0;
        $retireLTGains = 0.0;
        $retireLTLosses = 0.0;

        $totalWashDisallowed = 0.0;

        foreach ($realizations as $r) {
            $sellDate = $r['sellDate'] ?? '';
            if ($filterYear !== null && !str_starts_with($sellDate, $filterYear)) {
                continue;
            }

            $effectiveGain = isset($r['adjustedGain']) ? $r['adjustedGain'] : $r['realizedGain'];
            if (!empty($r['isWashSale'])) {
                $totalWashDisallowed += ($r['disallowedLoss'] ?? 0.0);
            }

            if (($r['taxStatus'] ?? '') === 'RETIREMENT_IRA') {
                if (($r['term'] ?? '') === 'LONG_TERM') {
                    if ($effectiveGain >= 0) $retireLTGains += $effectiveGain;
                    else $retireLTLosses += abs($effectiveGain);
                } else {
                    if ($effectiveGain >= 0) $retireSTGains += $effectiveGain;
                    else $retireSTLosses += abs($effectiveGain);
                }
            } else {
                if (($r['term'] ?? '') === 'LONG_TERM') {
                    if ($effectiveGain >= 0) $taxableLTGains += $effectiveGain;
                    else $taxableLTLosses += abs($effectiveGain);
                } else {
                    if ($effectiveGain >= 0) $taxableSTGains += $effectiveGain;
                    else $taxableSTLosses += abs($effectiveGain);
                }
            }
        }

        // Aggregate Dividend and Interest Income
        $qualifiedDividends = 0.0;
        $ordinaryDividends = 0.0;
        $interestIncome = 0.0;
        $foreignTaxCredit = 0.0;
        $retireIncome = 0.0;

        foreach ($incomeRecords as $inc) {
            $incDate = $inc['date'] ?? '';
            if ($filterYear !== null && !str_starts_with($incDate, $filterYear)) {
                continue;
            }

            if (($inc['taxStatus'] ?? '') === 'RETIREMENT_IRA') {
                $retireIncome += ($inc['amount'] ?? 0.0);
                continue;
            }

            $cat = $inc['category'] ?? '';
            $amt = (float) ($inc['amount'] ?? 0.0);

            if ($cat === 'QUALIFIED_DIVIDEND') {
                $qualifiedDividends += $amt;
            } elseif ($cat === 'INTEREST_INCOME') {
                $interestIncome += $amt;
            } elseif ($cat === 'FOREIGN_TAX_PAID') {
                $foreignTaxCredit += abs($amt);
            } else {
                $ordinaryDividends += $amt;
            }
        }

        // STATUTORY SCHEDULE D NETTING ALGORITHM (IRC § 1222)
        $initialNetST = $taxableSTGains - $taxableSTLosses;
        $initialNetLT = $taxableLTGains - $taxableLTLosses;

        // Apply prior-year loss carryforwards (IRC § 1212(b))
        $stCarryforwardIn = (float) ($priorYearCarryforwards['shortTerm'] ?? 0.0);
        $ltCarryforwardIn = (float) ($priorYearCarryforwards['longTerm'] ?? 0.0);

        $netSTAfterCarry = $initialNetST - $stCarryforwardIn;
        $netLTAfterCarry = $initialNetLT - $ltCarryforwardIn;

        // Cross-netting
        $finalNetST = $netSTAfterCarry;
        $finalNetLT = $netLTAfterCarry;

        if ($finalNetST > 0 && $finalNetLT < 0) {
            // LT loss offsets ST gain
            $offset = min($finalNetST, abs($finalNetLT));
            $finalNetST -= $offset;
            $finalNetLT += $offset;
        } elseif ($finalNetLT > 0 && $finalNetST < 0) {
            // ST loss offsets LT gain
            $offset = min($finalNetLT, abs($finalNetST));
            $finalNetLT -= $offset;
            $finalNetST += $offset;
        }

        $totalNetCapitalGain = $finalNetST + $finalNetLT;
        $lossDeductionAllowed = 0.0;
        $carryforwardToNextYear = 0.0;

        if ($totalNetCapitalGain < 0) {
            $lossDeductionAllowed = min(self::CAPITAL_LOSS_LIMIT, abs($totalNetCapitalGain));
            $carryforwardToNextYear = abs($totalNetCapitalGain) - $lossDeductionAllowed;
        }

        // Capital Gains Tax
        $taxableSTGainForTax = max(0.0, $finalNetST);
        $taxableLTGainForTax = max(0.0, $finalNetLT);

        $capitalGainsTax = ($taxableSTGainForTax * $stRate) + ($taxableLTGainForTax * $ltRate);

        // Dividend & Interest Tax
        $dividendTax = ($qualifiedDividends * $ltRate) + ($ordinaryDividends * $stRate) + ($interestIncome * $stRate);
        $dividendTax = max(0.0, $dividendTax - $foreignTaxCredit);

        $totalEstimatedTaxLiability = max(0.0, $capitalGainsTax + $dividendTax);

        return [
            'taxRates' => [
                'shortTerm' => $stRate,
                'longTerm' => $ltRate,
            ],
            'taxable' => [
                'grossSTGains' => $taxableSTGains,
                'grossSTLosses' => $taxableSTLosses,
                'netSTBeforeCarry' => $initialNetST,
                'grossLTGains' => $taxableLTGains,
                'grossLTLosses' => $taxableLTLosses,
                'netLTBeforeCarry' => $initialNetLT,
                'totalNetBeforeCarry' => $initialNetST + $initialNetLT,
                'stCarryforwardApplied' => min($stCarryforwardIn, max(0.0, $initialNetST)),
                'ltCarryforwardApplied' => min($ltCarryforwardIn, max(0.0, $initialNetLT)),
                'finalNetST' => $finalNetST,
                'finalNetLT' => $finalNetLT,
                'totalNetCapitalGain' => $totalNetCapitalGain,
                'lossDeductionAllowed' => $lossDeductionAllowed,
                'carryforwardToNextYear' => $carryforwardToNextYear,
                'washSaleDisallowed' => $totalWashDisallowed,
                'capitalGainsTax' => $capitalGainsTax,
            ],
            'income' => [
                'qualifiedDividends' => $qualifiedDividends,
                'ordinaryDividends' => $ordinaryDividends,
                'interestIncome' => $interestIncome,
                'foreignTaxCredit' => $foreignTaxCredit,
                'dividendTax' => $dividendTax,
            ],
            'retirement' => [
                'stRealized' => $retireSTGains - $retireSTLosses,
                'ltRealized' => $retireLTGains - $retireLTLosses,
                'totalRealized' => ($retireSTGains - $retireSTLosses) + ($retireLTGains - $retireLTLosses),
                'income' => $retireIncome,
                'estTax' => 0.0, // Tax-exempt
                'status' => 'TAX_EXEMPT_DEFERRED',
            ],
            'totalEstimatedTaxLiability' => $totalEstimatedTaxLiability,
        ];
    }

    /**
     * Calculates the cumulative capital loss carryforward into a target tax year
     * by walking the history of taxable net gains and losses year-by-year under IRC § 1212(b).
     *
     * @param array $realizations List of calculated realization records.
     * @param int   $targetYear   Target tax year (e.g. 2026).
     * @return array Calculated carryforwards ['shortTerm' => float, 'longTerm' => float, 'total' => float, 'yearlyHistory' => array].
     */
    public function calculateHistoricalLossCarryforwards(array $realizations, int $targetYear): array
    {
        // 1. Group taxable gains and losses by year
        $yearsData = [];
        foreach ($realizations as $r) {
            if (($r['taxStatus'] ?? '') !== 'TAXABLE') {
                continue;
            }
            $date = $r['sellDate'] ?? '';
            $year = (int) substr($date, 0, 4);
            if ($year <= 0 || $year >= $targetYear) {
                continue;
            }

            if (!isset($yearsData[$year])) {
                $yearsData[$year] = ['st' => 0.0, 'lt' => 0.0];
            }

            $gain = isset($r['adjustedGain']) ? $r['adjustedGain'] : ($r['realizedGain'] ?? 0.0);
            if (($r['term'] ?? '') === 'LONG_TERM') {
                $yearsData[$year]['lt'] += $gain;
            } else {
                $yearsData[$year]['st'] += $gain;
            }
        }

        ksort($yearsData);

        $stCarry = 0.0;
        $ltCarry = 0.0;
        $history = [];

        foreach ($yearsData as $y => $d) {
            $netST = $d['st'] - $stCarry;
            $netLT = $d['lt'] - $ltCarry;

            // Cross-netting
            if ($netST > 0 && $netLT < 0) {
                $offset = min($netST, abs($netLT));
                $netST -= $offset;
                $netLT += $offset;
            } elseif ($netLT > 0 && $netST < 0) {
                $offset = min($netLT, abs($netST));
                $netLT -= $offset;
                $netST += $offset;
            }

            $netTotal = $netST + $netLT;
            $allowedDeduction = 0.0;
            $excessLoss = 0.0;

            if ($netTotal < 0) {
                $allowedDeduction = min(self::CAPITAL_LOSS_LIMIT, abs($netTotal));
                $excessLoss = abs($netTotal) - $allowedDeduction;

                // Carry forward allocation: allocate excess loss based on proportion of ST vs LT loss
                if ($netST < 0 && $netLT < 0) {
                    $totalLoss = abs($netST) + abs($netLT);
                    $stCarry = $excessLoss * (abs($netST) / $totalLoss);
                    $ltCarry = $excessLoss * (abs($netLT) / $totalLoss);
                } elseif ($netST < 0) {
                    $stCarry = $excessLoss;
                    $ltCarry = 0.0;
                } else {
                    $stCarry = 0.0;
                    $ltCarry = $excessLoss;
                }
            } else {
                $stCarry = 0.0;
                $ltCarry = 0.0;
            }

            $history[$y] = [
                'year' => $y,
                'rawST' => $d['st'],
                'rawLT' => $d['lt'],
                'netTotal' => $netTotal,
                'allowedDeduction' => $allowedDeduction,
                'carryforwardOut' => $stCarry + $ltCarry,
            ];
        }

        return [
            'shortTerm' => $stCarry,
            'longTerm' => $ltCarry,
            'total' => $stCarry + $ltCarry,
            'yearlyHistory' => $history,
        ];
    }

    /**
     * Extracts expiration date (YYYY-MM-DD) from a canonical OCC option symbol.
     *
     * @param string $canonicalSym Canonical OCC option symbol string.
     * @return string|null Expiration date string in YYYY-MM-DD format, or null if unparseable.
     */
    private static function extractOptionExpiration(string $canonicalSym): ?string
    {
        if (preg_match('/\s*(\d{2})(\d{2})(\d{2})[CP]/', $canonicalSym, $m)) {
            return "20{$m[1]}-{$m[2]}-{$m[3]}";
        }
        if (preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', $canonicalSym, $m)) {
            return "{$m[3]}-{$m[1]}-{$m[2]}";
        }
        return null;
    }
}
