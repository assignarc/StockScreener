<?php

namespace App\Service;

class TaxEngine
{
    /**
     * Standard short-term capital gains tax rate (20% default rate).
     */
    private const DEFAULT_SHORT_TERM_RATE = 0.20;

    /**
     * Standard long-term capital gains tax rate (15% flat capital gain rate).
     */
    private const DEFAULT_LONG_TERM_RATE = 0.15;

    private function resolveSymbol(string $symbol, ?string $itemDesc = null): string
    {
        $clean = strtoupper(trim($symbol));
        if ($itemDesc && stripos($itemDesc, 'CD') !== false) {
            return $clean . ' (Bank CD)';
        }
        return $clean;
    }

    public function calculateTaxRealizations(array $history, array $currentPortfolio): array
    {
        // 1. Map current portfolio average prices by symbol/account for fallback cost basis
        $currentCostBasisMap = [];
        foreach ($currentPortfolio['accounts'] ?? [] as $acc) {
            $accNum = (string) ($acc['accountNumber'] ?? $acc['account_num'] ?? '');
            $nick = (string) ($acc['nickname'] ?? '');
            $maskedNum = $accNum !== '' ? ('***' . substr($accNum, -4)) : '';
            foreach ($acc['positions'] ?? [] as $pos) {
                $sym = $pos['symbol'] ?? '';
                $cost = (float) ($pos['cost_basis'] ?? $pos['averagePrice'] ?? 0.0);
                if ($sym && $cost > 0) {
                    if ($accNum) $currentCostBasisMap[$accNum][$sym] = $cost;
                    if ($maskedNum) $currentCostBasisMap[$maskedNum][$sym] = $cost;
                    if ($nick) $currentCostBasisMap[$nick][$sym] = $cost;
                    $currentCostBasisMap['ALL'][$sym] = $cost;
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
            $rawAcc = (string) ($tx['account_number'] ?? '');
            $accNick = (string) ($tx['account_nickname'] ?? '');
            $accKey = $accNick !== '' ? $accNick : ($rawAcc !== '' ? $rawAcc : 'PRIMARY');
            $maskedKey = $rawAcc !== '' ? ('***' . substr($rawAcc, -4)) : '';

            $date = $tx['date'] ?? '';
            $netAmount = (float) ($tx['amount'] ?? 0.0);
            $type = strtoupper($tx['type'] ?? '');

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
                    $positionEffect = strtoupper($item['position_effect'] ?? '');
                    // Written option / Sell to Open (net positive premium received or opening position effect)
                    if ($netAmount > 0 || $positionEffect === 'OPENING' || $qty < 0) {
                        $optionRecord = [
                            'date' => $date,
                            'qty' => abs($qty),
                            'premium' => abs($netAmount), // Premium received
                            'price' => $price,
                            'fees' => (float) ($tx['fees'] ?? 0.0),
                        ];
                        $buyQueues[$accKey][$symbol][] = $optionRecord;
                        if ($maskedKey && $maskedKey !== $accKey) {
                            $buyQueues[$maskedKey][$symbol][] = $optionRecord;
                        }
                        $buyQueues['ALL'][$symbol][] = $optionRecord;
                    } else {
                        // Buy to Close / Closing option trade (net negative outlay paid)
                        $closeQty = abs($qty);
                        $remainingCloseQty = $closeQty;
                        $totalRealized = 0.0;
                        $buyDate = 'N/A';
                        $costBasis = 0.0;
                        $initialPremiumReceived = 0.0;

                        // Check primary key, masked key, or global symbol queue
                        $targetQueueKey = null;
                        if (!empty($buyQueues[$accKey][$symbol])) {
                            $targetQueueKey = $accKey;
                        } elseif ($maskedKey && !empty($buyQueues[$maskedKey][$symbol])) {
                            $targetQueueKey = $maskedKey;
                        } elseif (!empty($buyQueues['ALL'][$symbol])) {
                            $targetQueueKey = 'ALL';
                        }

                        if ($targetQueueKey !== null) {
                            while ($remainingCloseQty > 0 && !empty($buyQueues[$targetQueueKey][$symbol])) {
                                $oldestWrite = &$buyQueues[$targetQueueKey][$symbol][0];
                                $matchQty = min($remainingCloseQty, $oldestWrite['qty']);
                                
                                $ratio = $matchQty / $oldestWrite['qty'];
                                $allocatedPremium = $oldestWrite['premium'] * $ratio;
                                $allocatedFees = $oldestWrite['fees'] * $ratio;

                                // Cost to buy back option contract
                                $paidAmt = abs($netAmount) * ($matchQty / $closeQty);
                                $gain = $allocatedPremium - $paidAmt - $allocatedFees;
                                $totalRealized += $gain;

                                $buyDate = $oldestWrite['date'];
                                $initialPremiumReceived += $allocatedPremium;
                                $costBasis += $paidAmt;

                                $oldestWrite['qty'] -= $matchQty;
                                $remainingCloseQty -= $matchQty;

                                if ($oldestWrite['qty'] <= 0) {
                                    array_shift($buyQueues[$targetQueueKey][$symbol]);
                                }
                            }
                        }

                        // If un-matched (no opening trade in window), cost basis is what was paid to buy back, premium is 0
                        if ($costBasis == 0.0) {
                            $costBasis = abs($netAmount);
                            $totalRealized = -$costBasis;
                        }

                        $realizations[] = [
                            'symbol' => $symbol,
                            'description' => $desc,
                            'assetType' => 'OPTION',
                            'account' => $accKey,
                            'sellDate' => $date,
                            'buyDate' => $buyDate,
                            'qty' => $closeQty,
                            'costBasis' => $costBasis,
                            'proceeds' => $initialPremiumReceived,
                            'realizedGain' => $totalRealized,
                            'term' => 'SHORT_TERM',
                            'taxRate' => self::DEFAULT_SHORT_TERM_RATE,
                            'estTax' => $totalRealized > 0 ? ($totalRealized * self::DEFAULT_SHORT_TERM_RATE) : 0.0,
                        ];
                    }
                } else {
                    // Equity/Stock trades
                    if ($qty > 0) {
                        // BUY TO OPEN - Push into account queue AND global fallback queue
                        $buyCost = abs($netAmount);
                        if ($buyCost == 0.0 && $item && isset($item['cost'])) {
                            $buyCost = abs((float) $item['cost']);
                        }

                        $buyRecord = [
                            'date' => $date,
                            'qty' => $qty,
                            'price' => $price,
                            'cost' => $buyCost,
                            'fees' => (float) ($tx['fees'] ?? 0.0),
                        ];

                        $buyQueues[$accKey][$symbol][] = $buyRecord;
                        if ($maskedKey && $maskedKey !== $accKey) {
                            $buyQueues[$maskedKey][$symbol][] = $buyRecord;
                        }
                        $buyQueues['ALL'][$symbol][] = $buyRecord;
                    } else {
                        // SELL TO CLOSE
                        $remainingSellQty = abs($qty);
                        $totalCostBasis = 0.0;
                        $buyDate = '';
                        $oldestBuyDateObj = null;

                        // 1. First priority: Match against FIFO buy queue from history (captures actual buy cash outlay)
                        $targetQueueKey = null;
                        if (!empty($buyQueues[$accKey][$symbol])) {
                            $targetQueueKey = $accKey;
                        } elseif ($maskedKey && !empty($buyQueues[$maskedKey][$symbol])) {
                            $targetQueueKey = $maskedKey;
                        } elseif (!empty($buyQueues['ALL'][$symbol])) {
                            $targetQueueKey = 'ALL';
                        }

                        if ($targetQueueKey !== null) {
                            while ($remainingSellQty > 0 && !empty($buyQueues[$targetQueueKey][$symbol])) {
                                $oldestBuy = &$buyQueues[$targetQueueKey][$symbol][0];
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
                                    array_shift($buyQueues[$targetQueueKey][$symbol]);
                                }
                            }
                        }

                        // 2. Second priority: If sell quantity exceeds matched FIFO buys, check broker reported transaction cost
                        if ($remainingSellQty > 0) {
                            $txItemCost = (float) ($item['cost'] ?? $item['cost_basis'] ?? $item['costBasis'] ?? $tx['cost_basis'] ?? $tx['costBasis'] ?? 0.0);
                            // Only use txItemCost if it's reasonably proportional (avoiding unit quantity misinterpretation)
                            if ($txItemCost > 0 && ($netAmount == 0.0 || $txItemCost > ($netAmount * 0.1))) {
                                $totalCostBasis += $txItemCost;
                                $remainingSellQty = 0;
                                if ($buyDate === '' && (isset($item['purchase_date']) || isset($tx['purchase_date']))) {
                                    $buyDate = $item['purchase_date'] ?? $tx['purchase_date'];
                                    $oldestBuyDateObj = new \DateTimeImmutable($buyDate);
                                }
                            }
                        }

                        // 3. Third priority: Portfolio average cost basis for pre-2022 / unmatched history
                        if ($remainingSellQty > 0) {
                                $fallbackAvg = $currentCostBasisMap[$accKey][$symbol] 
                                    ?? $currentCostBasisMap[$maskedKey][$symbol] 
                                    ?? $currentCostBasisMap['ALL'][$symbol] 
                                    ?? 0.0;

                                if ($fallbackAvg > 0) {
                                    $totalCostBasis += $fallbackAvg * $remainingSellQty;
                                } else {
                                    $totalCostBasis += 0.0;
                                }
                                if ($buyDate === '') {
                                    $buyDate = '2022-01-01 (Pre-existing)';
                                }
                            }

                        $proceeds = $netAmount;
                        $realizedGain = $proceeds - $totalCostBasis - (float) ($tx['fees'] ?? 0.0);

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
                        $realizations[] = [
                            'rawSymbol' => $symbol,
                            'symbol' => $this->resolveSymbol($symbol, $itemDesc),
                            'description' => $itemDesc,
                            'assetType' => $assetType !== '' ? $assetType : 'EQUITY',
                            'account' => $accKey,
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
                // If it is an option expiration / assignment, all written options in queue are realized
                $targetQueues = [
                    $accKey => &$buyQueues[$accKey][$symbol],
                    $maskedKey => &$buyQueues[$maskedKey][$symbol],
                    'ALL' => &$buyQueues['ALL'][$symbol],
                ];

                foreach ($targetQueues as $qKey => &$q) {
                    if (!empty($q)) {
                        foreach ($q as $oldestWrite) {
                            if (($oldestWrite['qty'] ?? 0) > 0) {
                                $gain = $oldestWrite['premium'] - $oldestWrite['fees'];
                                $realizations[] = [
                                    'rawSymbol' => $symbol,
                                    'symbol' => $symbol,
                                    'description' => $desc,
                                    'assetType' => 'OPTION',
                                    'account' => $accKey,
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
                        }
                        $q = [];
                    }
                }
            }
        }

        return $realizations;
    }
}
