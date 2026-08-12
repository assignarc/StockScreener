<?php

namespace App\Llm;

interface LlmServiceInterface
{
    /**
     * Generates a set of capital flywheel strategy ideas based on the current portfolio state and tracked screener stocks.
     */
    public function generateFlywheelIdeas(array $portfolio, array $trackedStocks = [], array $marketIntelligence = []): array;

    /**
     * Synthesizes raw market news into a structured macroeconomic intelligence report.
     */
    public function analyzeMarketNews(array $newsItems): array;

    /**
     * Evaluates a live option chain and selects recommended strikes.
     */
    public function analyzeOptionChain(string $symbol, float $currentPrice, array $chain): array;

    /**
     * Conducts pre-trade sanity checks against market context/risks.
     */
    public function verifyTradePreExecution(array $trade): array;

    /**
     * Reviews an active option position for risk of assignment and greek decay.
     * Uses provided liveChain data to prevent hallucination.
     */
    public function reviewOptionPosition(string $symbol, array $contractData, array $liveChain): array;

    /**
     * Returns a user-friendly identifier for the model backend.
     */
    public function getProviderName(): string;
}
