<?php

namespace App\Llm;

use App\Service\AppConfigService;

/**
 * Class LlmServiceRouter
 *
 * Implements LlmServiceInterface as a dynamic router and fallback proxy.
 * Inspects system configuration to dispatch requests to the active provider (Gemini, OpenAI, Claude, Local)
 * and provides automatic cascading fallback if the primary provider encounters quota or rate-limit errors.
 *
 * Design Reference: doc/llm-analysis.md
 */
class LlmServiceRouter implements LlmServiceInterface
{
    /**
     * @param AppConfigService $appConfig Application key-value configuration service.
     * @param GeminiService $geminiService Google Gemini provider adapter.
     * @param OpenAiLlmService $openAiService OpenAI and local OpenAI-compatible provider adapter.
     * @param ClaudeService $claudeService Anthropic Claude provider adapter.
     */
    public function __construct(
        private AppConfigService $appConfig,
        private GeminiService $geminiService,
        private OpenAiLlmService $openAiService,
        private ClaudeService $claudeService
    ) {}

    /**
     * Resolve currently active LLM service adapter based on user configuration.
     *
     * @return LlmServiceInterface Active service instance.
     */
    private function getActiveService(): LlmServiceInterface
    {
        $provider = (string) $this->appConfig->get('llm.provider', 'gemini');
        if ($provider === 'openai' || $provider === 'local') {
            return $this->openAiService;
        }
        if ($provider === 'claude') {
            return $this->claudeService;
        }
        return $this->geminiService;
    }

    /**
     * Execute LLM operation on active provider, cascading to alternative providers upon rate-limiting (429/quota).
     *
     * @param callable $call Callback function receiving an LlmServiceInterface instance.
     * @return mixed Result of successful service execution.
     * @throws \Throwable If all eligible provider candidates fail.
     */
    private function executeWithFallback(callable $call): mixed
    {
        try {
            return $call($this->getActiveService());
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $isQuota = (str_contains($msg, '429') || stripos($msg, 'quota') !== false || stripos($msg, 'rate limit') !== false);
            
            if ($isQuota) {
                // Primary provider encountered a rate limit or quota exhaustion; try fallback providers
                $active = $this->getActiveService();
                $fallbacks = [$this->openAiService, $this->claudeService, $this->geminiService];
                
                foreach ($fallbacks as $fb) {
                    if ($fb === $active) continue;
                    try {
                        return $call($fb);
                    } catch (\Throwable $fbErr) {
                        continue; // Attempt next fallback candidate
                    }
                }
            }
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function generateFlywheelIdeas(array $portfolio, array $trackedStocks = [], array $marketIntelligence = []): array
    {
        return $this->executeWithFallback(fn(LlmServiceInterface $s) => $s->generateFlywheelIdeas($portfolio, $trackedStocks, $marketIntelligence));
    }

    /**
     * {@inheritdoc}
     */
    public function analyzeMarketNews(array $newsItems): array
    {
        return $this->executeWithFallback(fn(LlmServiceInterface $s) => $s->analyzeMarketNews($newsItems));
    }

    /**
     * {@inheritdoc}
     */
    public function analyzeOptionChain(string $symbol, float $currentPrice, array $chain): array
    {
        return $this->executeWithFallback(fn(LlmServiceInterface $s) => $s->analyzeOptionChain($symbol, $currentPrice, $chain));
    }

    /**
     * {@inheritdoc}
     */
    public function verifyTradePreExecution(array $trade): array
    {
        return $this->executeWithFallback(fn(LlmServiceInterface $s) => $s->verifyTradePreExecution($trade));
    }

    /**
     * {@inheritdoc}
     */
    public function reviewOptionPosition(string $symbol, array $contractData, array $liveChain): array
    {
        return $this->executeWithFallback(fn(LlmServiceInterface $s) => $s->reviewOptionPosition($symbol, $contractData, $liveChain));
    }

    /**
     * {@inheritdoc}
     */
    public function getProviderName(): string
    {
        return $this->getActiveService()->getProviderName();
    }
}
