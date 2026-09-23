<?php

namespace App\Llm;

/**
 * Interface LlmServiceInterface
 *
 * Defines the contract for artificial intelligence strategy engines and market intelligence analyzers.
 * Concrete implementations (Gemini, OpenAI, Claude) evaluate portfolio structures, analyze option chains,
 * conduct pre-trade verification checks, and synthesize macroeconomic news.
 *
 * Design Reference: doc/llm-analysis.md
 */
interface LlmServiceInterface
{
    /**
     * Generate structured Capital Flywheel strategy ideas based on current portfolio holdings,
     * available idle cash, unencumbered share blocks, and tracked screener stocks.
     *
     * @param array $portfolio Normalized portfolio array including cash and positions.
     * @param array $trackedStocks List of tracked stock entities or symbol dictionaries.
     * @param array $marketIntelligence Macroeconomic context and sector sentiment picks.
     * @return array List of validated strategy ideas or error response structure.
     */
    public function generateFlywheelIdeas(array $portfolio, array $trackedStocks = [], array $marketIntelligence = []): array;

    /**
     * Synthesize raw market news articles into a structured macroeconomic intelligence report.
     *
     * @param array $newsItems List of market news items from market data provider.
     * @return array Structured array with key events and bullish/bearish stock picks.
     */
    public function analyzeMarketNews(array $newsItems): array;

    /**
     * Evaluate a live option chain and recommend optimal strike prices for Covered Calls
     * and Cash-Secured Puts using delta target thresholds and out-of-the-money buffers.
     *
     * @param string $symbol Underlying equity ticker symbol.
     * @param float $currentPrice Current market price of underlying equity.
     * @param array $chain Normalized option chain array containing call and put contracts.
     * @return array Structured strike recommendations and AI analytical reasoning.
     */
    public function analyzeOptionChain(string $symbol, float $currentPrice, array $chain): array;

    /**
     * Conduct real-time pre-trade sanity checks against upcoming earnings catalysts,
     * macroeconomic risk factors, and order execution limit guidelines.
     *
     * @param array $trade Associative array containing symbol, action, strike, and strategy.
     * @return array Verification verdict (PASS, WARN, REJECT) with rationale.
     */
    public function verifyTradePreExecution(array $trade): array;

    /**
     * Review an active option position nearing expiration to assess assignment risk,
     * delta decay, and recommend optimal management actions (Hold, Buy-To-Close, Roll).
     *
     * @param string $symbol Underlying equity ticker symbol.
     * @param array $contractData Position details including strike, expiration, and quantity.
     * @param array $liveChain Filtered option chain data for nearby strikes.
     * @return array Structured position review with decision, status, and target limit price.
     */
    public function reviewOptionPosition(string $symbol, array $contractData, array $liveChain): array;

    /**
     * Get user-friendly identifier and active model name for the backend provider.
     *
     * @return string Human-readable provider label (e.g., 'Google Gemini (gemini-2.5-flash)').
     */
    public function getProviderName(): string;
}
