<?php

namespace App\Llm;

use App\Service\AppConfigService;

class LlmServiceRouter implements LlmServiceInterface
{
    public function __construct(
        private AppConfigService $appConfig,
        private GeminiService $geminiService,
        private OpenAiLlmService $openAiService,
        private ClaudeService $claudeService
    ) {}

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

    private function executeWithFallback(callable $call): mixed
    {
        try {
            return $call($this->getActiveService());
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $isQuota = (str_contains($msg, '429') || stripos($msg, 'quota') !== false || stripos($msg, 'rate limit') !== false);
            
            if ($isQuota) {
                // Primary provider hit rate limit - attempt fallback to secondary providers
                $active = $this->getActiveService();
                $fallbacks = [$this->openAiService, $this->claudeService, $this->geminiService];
                
                foreach ($fallbacks as $fb) {
                    if ($fb === $active) continue;
                    try {
                        return $call($fb);
                    } catch (\Throwable $fbErr) {
                        continue; // try next fallback
                    }
                }
            }
            throw $e;
        }
    }

    public function generateFlywheelIdeas(array $portfolio, array $trackedStocks = [], array $marketIntelligence = []): array
    {
        return $this->executeWithFallback(fn(LlmServiceInterface $s) => $s->generateFlywheelIdeas($portfolio, $trackedStocks, $marketIntelligence));
    }

    public function analyzeMarketNews(array $newsItems): array
    {
        return $this->executeWithFallback(fn(LlmServiceInterface $s) => $s->analyzeMarketNews($newsItems));
    }

    public function analyzeOptionChain(string $symbol, float $currentPrice, array $chain): array
    {
        return $this->executeWithFallback(fn(LlmServiceInterface $s) => $s->analyzeOptionChain($symbol, $currentPrice, $chain));
    }

    public function verifyTradePreExecution(array $trade): array
    {
        return $this->executeWithFallback(fn(LlmServiceInterface $s) => $s->verifyTradePreExecution($trade));
    }

    public function reviewOptionPosition(string $symbol, array $contractData, array $liveChain): array
    {
        return $this->executeWithFallback(fn(LlmServiceInterface $s) => $s->reviewOptionPosition($symbol, $contractData, $liveChain));
    }

    public function getProviderName(): string
    {
        return $this->getActiveService()->getProviderName();
    }
}
