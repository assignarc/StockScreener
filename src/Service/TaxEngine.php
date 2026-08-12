<?php

namespace App\Service;

class TaxEngine
{
    /**
     * Standard short-term capital gains tax rate estimation (default 24% for middle bracket).
     */
    private const DEFAULT_SHORT_TERM_RATE = 0.24;

    /**
     * Standard long-term capital gains tax rate estimation (default 15% for middle bracket).
     */
    private const DEFAULT_LONG_TERM_RATE = 0.15;

    public function calculateTaxRealizations(array $history, array $currentPortfolio): array
    {
        // 1. Map current portfolio average prices by symbol/account for fallback cost basis
        $currentCostBasisMap = [];
        foreach ($currentPortfolio['accounts'] ?? [] as $acc) {
            $accNum = $acc['accountNumber'] ?? '';
            $maskedNum = $accNum !== '' ? ('***' . substr($accNum, -4)) : '';
            foreach ($acc['positions'] ?? [] as $pos) {
                $sym = $pos['symbol'] ?? '';
                $cost = (float) ($pos['cost_basis'] ?? $pos['averagePrice'] ?? 0.0);
                if ($sym && $cost > 0) {
                    $currentCostBasisMap[$maskedNum][$sym] = $cost;
                }
            }
        }

        $realizations = [];
        $buyQueues = []; // [account][symbol] => FIFO queue of buys

        // Sort history chronologically (oldest first) to track FIFO buys/sells
        $chronoHistory = $history;
        usort($chronoHistory, function ($a, $b) {
            return strcmp($a['date'] ?? '', $b['date'] ?? '');
        });

        foreach ($chronoHistory as $tx) {
            $accNum = $tx['account_number'] ?? 'UNKNOWN';
            $symbol = $tx['symbol'] ?? '';
            if (!$symbol || $symbol === 'CURRENCY_USD') {
                continue;
            }

            $date = $tx['date'] ?? '';
            $netAmount = (float) ($tx['amount'] ?? 0.0);
            $type = strtoupper($tx['type'] ?? '');
            
            $item = $tx['transfer_items'][0] ?? null;
            $qty = $item ? (float) ($item['amount'] ?? 0.0) : 0.0;
            $price = $item ? (float) ($item['price'] ?? 0.0) : 0.0;
            $assetType = $item ? strtoupper($item['asset_type'] ?? '') : '';

            $desc = $tx['description'] ?? '';
            $isExpiration = ($type === 'EXPIRATION' 
                || stripos($desc, 'EXPIRATION') !== false 
                || stripos($desc, 'EXPIRED') !== false 
                || stripos($desc, 'REMOVAL OF OPTION') !== false
                || stripos($desc, 'ASSIGNED') !== false
                || stripos($desc, 'ASSIGNMENT') !== false
                || stripos($desc, 'EXERCISE') !== false
            );

            $isTrade = ($type === 'TRADE' && !$isExpiration);
            $isOption = ($assetType === 'OPTION' || preg_match('/^[A-Z0-9]+\s*\d{6}[CP]\d{8}$/', $symbol));

            if ($isTrade) {
                if ($isOption) {
                    // Option writing (short Calls/Puts)
                    if ($qty < 0) {
                        // Written option (SELL TO OPEN)
                        $buyQueues[$accNum][$symbol][] = [
                            'date' => $date,
                            'qty' => abs($qty),
                            'premium' => $netAmount, // Received cash (positive)
                            'price' => $price,
                            'fees' => (float) ($tx['fees'] ?? 0.0),
                        ];
                    } else {
                        // BUY TO CLOSE (or buy to open, but flywheel mostly writes)
                        $remainingCloseQty = $qty;
                        $totalRealized = 0.0;
                        $buyDate = 'N/A';
                        $costBasis = 0.0;

                        while ($remainingCloseQty > 0 && !empty($buyQueues[$accNum][$symbol])) {
                            $oldestWrite = &$buyQueues[$accNum][$symbol][0];
                            $matchQty = min($remainingCloseQty, $oldestWrite['qty']);
                            
                            $ratio = $matchQty / $oldestWrite['qty'];
                            $allocatedPremium = $oldestWrite['premium'] * $ratio;
                            $allocatedFees = $oldestWrite['fees'] * $ratio;

                            // Realized gain = premium received - premium paid (which is negative netAmount)
                            $paidAmt = abs($netAmount) * ($matchQty / $qty);
                            $gain = $allocatedPremium - $paidAmt - $allocatedFees;
                            $totalRealized += $gain;

                            $buyDate = $oldestWrite['date'];
                            $costBasis += ($allocatedPremium - $allocatedFees);

                            $oldestWrite['qty'] -= $matchQty;
                            $remainingCloseQty -= $matchQty;

                            if ($oldestWrite['qty'] <= 0) {
                                array_shift($buyQueues[$accNum][$symbol]);
                            }
                        }

                        // Fallback cost basis if no matching write was found in history
                        if ($costBasis == 0.0) {
                            $gain = $netAmount; // Closed at a cost
                        }

                        $realizations[] = [
                            'symbol' => $symbol,
                            'assetType' => 'OPTION',
                            'account' => $tx['account_nickname'] ?? $accNum,
                            'sellDate' => $date,
                            'buyDate' => $buyDate,
                            'qty' => $qty,
                            'costBasis' => $costBasis,
                            'proceeds' => abs($netAmount),
                            'realizedGain' => $totalRealized,
                            'term' => 'SHORT_TERM', // Options are practically always short term
                            'taxRate' => self::DEFAULT_SHORT_TERM_RATE,
                            'estTax' => $totalRealized > 0 ? ($totalRealized * self::DEFAULT_SHORT_TERM_RATE) : 0.0,
                        ];
                    }
                } else {
                    // Equity/Stock trades
                    if ($qty > 0) {
                        // BUY TO OPEN
                        $buyQueues[$accNum][$symbol][] = [
                            'date' => $date,
                            'qty' => $qty,
                            'price' => $price,
                            'cost' => abs($netAmount), // Paid cash (positive representation of cost)
                            'fees' => (float) ($tx['fees'] ?? 0.0),
                        ];
                    } else {
                        // SELL TO CLOSE
                        $remainingSellQty = abs($qty);
                        $totalCostBasis = 0.0;
                        $buyDate = '';
                        $oldestBuyDateObj = null;

                        while ($remainingSellQty > 0 && !empty($buyQueues[$accNum][$symbol])) {
                            $oldestBuy = &$buyQueues[$accNum][$symbol][0];
                            $matchQty = min($remainingSellQty, $oldestBuy['qty']);
                            
                            $ratio = $matchQty / $oldestBuy['qty'];
                            $totalCostBasis += ($oldestBuy['cost'] * $ratio) + ($oldestBuy['fees'] * $ratio);

                            $bDate = $oldestBuy['date'];
                            if ($buyDate === '') {
                                $buyDate = $bDate;
                            }
                            $bDateObj = new \DateTimeImmutable($bDate);
                            if ($oldestBuyDateObj === null || $bDateObj < $oldestBuyDateObj) {
                                $oldestBuyDateObj = $bDateObj;
                            }

                            $oldestBuy['qty'] -= $matchQty;
                            $remainingSellQty -= $matchQty;

                            if ($oldestBuy['qty'] <= 0) {
                                array_shift($buyQueues[$accNum][$symbol]);
                            }
                        }

                        // Fallback cost basis if no matching buy was found in history
                        if ($remainingSellQty > 0) {
                            $fallbackAvg = $currentCostBasisMap[$accNum][$symbol] ?? 0.0;
                            if ($fallbackAvg > 0) {
                                $totalCostBasis += $fallbackAvg * $remainingSellQty;
                            } else {
                                // Default fallback to 80% of sell price if completely unknown
                                $totalCostBasis += ($price * 0.8) * $remainingSellQty;
                            }
                            $buyDate = 'Pre-existing';
                        }

                        $proceeds = $netAmount;
                        $realizedGain = $proceeds - $totalCostBasis - (float) ($tx['fees'] ?? 0.0);

                        // Calculate holding period term
                        $term = 'SHORT_TERM';
                        $rate = self::DEFAULT_SHORT_TERM_RATE;
                        if ($buyDate !== 'Pre-existing' && $oldestBuyDateObj !== null) {
                            $sellDateObj = new \DateTimeImmutable($date);
                            $diff = $sellDateObj->diff($oldestBuyDateObj);
                            if ($diff->y >= 1) {
                                $term = 'LONG_TERM';
                                $rate = self::DEFAULT_LONG_TERM_RATE;
                            }
                        }

                        $realizations[] = [
                            'symbol' => $symbol,
                            'assetType' => 'EQUITY',
                            'account' => $tx['account_nickname'] ?? $accNum,
                            'sellDate' => $date,
                            'buyDate' => $buyDate,
                            'qty' => abs($qty),
                            'costBasis' => $totalCostBasis,
                            'proceeds' => $proceeds,
                            'realizedGain' => $realizedGain,
                            'term' => $term,
                            'taxRate' => $rate,
                            'estTax' => $realizedGain > 0 ? ($realizedGain * $rate) : 0.0,
                        ];
                    }
                }
            } elseif ($isExpiration) {
                // If it is an option expiration, the write premium is 100% realized gain
                if (!empty($buyQueues[$accNum][$symbol])) {
                    foreach ($buyQueues[$accNum][$symbol] as $oldestWrite) {
                        $gain = $oldestWrite['premium'] - $oldestWrite['fees'];
                        $realizations[] = [
                            'symbol' => $symbol,
                            'assetType' => 'OPTION',
                            'account' => $tx['account_nickname'] ?? $accNum,
                            'sellDate' => $date,
                            'buyDate' => $oldestWrite['date'],
                            'qty' => $oldestWrite['qty'],
                            'costBasis' => 0.0,
                            'proceeds' => $oldestWrite['premium'],
                            'realizedGain' => $gain,
                            'term' => 'SHORT_TERM',
                            'taxRate' => self::DEFAULT_SHORT_TERM_RATE,
                            'estTax' => $gain > 0 ? ($gain * self::DEFAULT_SHORT_TERM_RATE) : 0.0,
                        ];
                    }
                    unset($buyQueues[$accNum][$symbol]);
                }
            }
        }

        return $realizations;
    }
}
