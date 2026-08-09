<?php

namespace App\Llm;

interface LlmServiceInterface
{
    /**
     * Calls AI model API to generate strategy ideas.
     */
    public function generateFlywheelIdeas(array $portfolio, array $trackedStocks = []): array;

    /**
     * Evaluates a live option chain and selects recommended strikes.
     */
    public function analyzeOptionChain(string $symbol, float $currentPrice, array $chain): array;

    /**
     * Conducts pre-trade sanity checks against market context/risks.
     */
    public function verifyTradePreExecution(array $trade): array;

    /**
     * Returns a user-friendly identifier for the model backend.
     */
    public function getProviderName(): string;
}
