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

    public function generateFlywheelIdeas(array $portfolio, array $trackedStocks = []): array
    {
        return $this->getActiveService()->generateFlywheelIdeas($portfolio, $trackedStocks);
    }

    public function analyzeOptionChain(string $symbol, float $currentPrice, array $chain): array
    {
        return $this->getActiveService()->analyzeOptionChain($symbol, $currentPrice, $chain);
    }

    public function verifyTradePreExecution(array $trade): array
    {
        return $this->getActiveService()->verifyTradePreExecution($trade);
    }

    public function getProviderName(): string
    {
        return $this->getActiveService()->getProviderName();
    }
}
