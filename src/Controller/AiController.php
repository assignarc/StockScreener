<?php

namespace App\Controller;

use App\Repository\StockRepository;
use App\Service\BrokerManagerService;
use App\Service\PersistentCacheService;
use App\Llm\LlmServiceRouter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/ai', name: 'api_ai_')]
class AiController extends AbstractController
{
    public function __construct(
        private LlmServiceRouter $llmService,
        private BrokerManagerService $brokerManager,
        private StockRepository $stockRepository,
        private PersistentCacheService $cache,
    ) {}

    /**
     * Returns AI-generated Capital Flywheel strategy ideas using live portfolio data.
     * The static hardcoded discover suggestions list has been replaced with a live LLM call.
     */
    #[Route('/flywheel-ideas', name: 'flywheel_ideas', methods: ['GET'])]
    public function flywheelIdeas(): JsonResponse
    {
        $cachedLandscape = $this->cache->get('flywheel.engine.landscape', isSensitive: true);
        if ($cachedLandscape && isset($cachedLandscape['newIdeas'])) {
            return $this->json(['status' => 'success', 'source' => 'cache', 'data' => $cachedLandscape['newIdeas']]);
        }

        $portfolio    = $this->brokerManager->getAggregatedPortfolio();
        $stocks       = $this->stockRepository->findByFilters();
        $aiIdeas      = $this->llmService->generateFlywheelIdeas($portfolio, $stocks);

        return $this->json(['status' => 'success', 'source' => 'live', 'data' => $aiIdeas]);
    }

    /**
     * Live AI option chain analysis for a specific symbol.
     */
    #[Route('/option-chain-analysis/{symbol}', name: 'option_chain_analysis', methods: ['GET'])]
    public function optionChainAnalysis(string $symbol): JsonResponse
    {
        $symbol       = strtoupper(trim($symbol));
        $stock        = $this->stockRepository->findOneBy(['symbol' => $symbol]);
        $currentPrice = $stock ? ($stock->getPrice() ?? 100.0) : 100.0;

        $chain    = $this->brokerManager->getOptionChain($symbol, $currentPrice);
        $analysis = $this->llmService->analyzeOptionChain($symbol, $currentPrice, $chain);

        return $this->json(['status' => 'success', 'data' => $analysis]);
    }

    /**
     * AI pre-trade verification / risk check before order submission.
     */
    #[Route('/verify-trade', name: 'verify_trade', methods: ['POST'])]
    public function verifyTrade(Request $request): JsonResponse
    {
        $trade  = json_decode($request->getContent(), true) ?? [];
        $result = $this->llmService->verifyTradePreExecution($trade);

        return $this->json(['status' => 'success', 'data' => $result]);
    }

    /**
     * AI review of an active option position nearing expiration.
     */
    #[Route('/review-option/{symbol}/{strike}', name: 'review_option', methods: ['GET'])]
    public function reviewOption(string $symbol, string $strike): JsonResponse
    {
        $symbol       = strtoupper(trim($symbol));
        $stock        = $this->stockRepository->findOneBy(['symbol' => $symbol]);
        $currentPrice = $stock ? $stock->getPrice() : 100.0;

        $chain = $this->brokerManager->getOptionChain($symbol, $currentPrice);
        $contractData = [
            'type' => 'CALL', // We assume call for simple review, but ideally frontend sends this
            'strike' => (float)$strike,
            'expiration' => '',
            'contracts' => 1
        ];

        $review = $this->llmService->reviewOptionPosition($symbol, $contractData, $chain);

        return $this->json(['status' => 'success', 'data' => $review]);
    }

    /**
     * AI-powered discover suggestions — replaces the old static hardcoded stock list.
     * Falls back to curated defaults when API key is not configured.
     */
    #[Route('/discover-suggestions', name: 'discover_suggestions', methods: ['GET'])]
    public function discoverSuggestions(): JsonResponse
    {
        $cachedLandscape = $this->cache->get('flywheel.engine.landscape', isSensitive: true);
        if ($cachedLandscape && isset($cachedLandscape['newIdeas'])) {
            $aiResult = $cachedLandscape['newIdeas'];
        } else {
            $portfolio = $this->brokerManager->getAggregatedPortfolio();
            $stocks    = $this->stockRepository->findByFilters();
            $aiResult  = $this->llmService->generateFlywheelIdeas($portfolio, $stocks);
        }

        // If Gemini returned live ideas, reformat them as discover-style cards
        if (!empty($aiResult['ideas'])) {
            $suggestions = array_map(function (array $idea) {
                return [
                    'symbol'          => $idea['ticker'] ?? 'N/A',
                    'name'            => $idea['title'] ?? ($idea['ticker'] ?? 'AI Idea'),
                    'sector'          => 'AI-Selected',
                    'price'           => null,
                    'targetPrice'     => null,
                    'score'           => null,
                    'risk'            => 'AI-Assessed',
                    'revGrowth'       => null,
                    'marketCap'       => null,
                    'reasoning'       => $idea['reasoning'] ?? '',
                    'catalysts'       => $idea['riskGuardrail'] ?? '',
                    'keyRisks'        => $idea['riskGuardrail'] ?? '',
                    'suggestedStrategy'=> $idea['strategyType'] ?? '',
                    'estimatedPremium'=> $idea['estimatedPremium'] ?? null,
                    'apy'             => $idea['APY'] ?? null,
                    'delta'           => $idea['delta'] ?? 'N/A',
                    'probabilityOfProfit'=> $idea['probabilityOfProfit'] ?? 'N/A',
                    'impliedVolatilityRank'=> $idea['impliedVolatilityRank'] ?? 'N/A',
                    'source'          => $aiResult['source'] ?? 'AI Engine',
                    '_live'           => true,
                ];
            }, $aiResult['ideas']);

            return $this->json(['status' => 'success', 'source' => 'ai_live', 'data' => $suggestions]);
        }

        // Curated fallback when Gemini API key is not configured or call fails
        $suggestions = [
            [
                'symbol'          => 'AMD',
                'name'            => 'Advanced Micro Devices Inc',
                'sector'          => 'Semiconductors',
                'price'           => 142.50,
                'targetPrice'     => 185.00,
                'score'           => 84,
                'risk'            => 'MED',
                'revGrowth'       => '+24.5%',
                'marketCap'       => '$230B',
                'reasoning'       => 'Dominating the x86 server market and rapidly expanding MI300X AI GPU market share.',
                'catalysts'       => 'MI350 series launch, datacenter market share gains.',
                'keyRisks'        => 'Competition from NVIDIA Blackwell.',
                'suggestedStrategy'=> '<span class="material-symbols-outlined" style="font-size:12px;vertical-align:middle;color:var(--green);">check_circle</span> Level 1 Basic: Long Call 5% OTM Target $150 (30-60 DTE)',
            ],
            [
                'symbol'          => 'AMZN',
                'name'            => 'Amazon.com Inc',
                'sector'          => 'Consumer Cyclical',
                'price'           => 186.20,
                'targetPrice'     => 225.00,
                'score'           => 88,
                'risk'            => 'LOW',
                'revGrowth'       => '+14.2%',
                'marketCap'       => '$1.94T',
                'reasoning'       => 'AWS cloud growth re-accelerating past +19% YoY.',
                'catalysts'       => 'AWS AI workload adoption, Prime Video ad network.',
                'keyRisks'        => 'Consumer spending slowdown.',
                'suggestedStrategy'=> '<span class="material-symbols-outlined" style="font-size:12px;vertical-align:middle;color:var(--yellow);">warning</span> Level 1 Basic: Cash-Secured Put at $170 (8% Discount)',
            ],
            [
                'symbol'          => 'MSFT',
                'name'            => 'Microsoft Corporation',
                'sector'          => 'Software',
                'price'           => 415.80,
                'targetPrice'     => 490.00,
                'score'           => 92,
                'risk'            => 'LOW',
                'revGrowth'       => '+15.8%',
                'marketCap'       => '$3.09T',
                'reasoning'       => 'Azure OpenAI leading corporate AI cloud migration.',
                'catalysts'       => 'Copilot seat expansion, Azure growth.',
                'keyRisks'        => 'AI datacenter capex intensity.',
                'suggestedStrategy'=> '<span class="material-symbols-outlined" style="font-size:12px;vertical-align:middle;color:var(--yellow);">warning</span> Level 1 Basic: Cash-Secured Put at $385',
            ],
            [
                'symbol'          => 'META',
                'name'            => 'Meta Platforms Inc',
                'sector'          => 'Communication Services',
                'price'           => 510.40,
                'targetPrice'     => 610.00,
                'score'           => 89,
                'risk'            => 'LOW',
                'revGrowth'       => '+22.1%',
                'marketCap'       => '$1.29T',
                'reasoning'       => 'AI recommendation engine driving massive engagement gains.',
                'catalysts'       => 'Llama 4 rollout, Advantage+ AI ad suite.',
                'keyRisks'        => 'EU data privacy regulations.',
                'suggestedStrategy'=> '<span class="material-symbols-outlined" style="font-size:12px;vertical-align:middle;color:var(--green);">check_circle</span> Level 1 Basic: Defined-Risk Long Call at $530 (45 DTE)',
            ],
            [
                'symbol'          => 'AVGO',
                'name'            => 'Broadcom Inc',
                'sector'          => 'Semiconductors',
                'price'           => 162.30,
                'targetPrice'     => 205.00,
                'score'           => 86,
                'risk'            => 'LOW',
                'revGrowth'       => '+43.0%',
                'marketCap'       => '$755B',
                'reasoning'       => 'Exclusive custom ASIC AI chip partner for Google, Meta, and ByteDance.',
                'catalysts'       => 'Custom AI accelerator orders, VMware migration.',
                'keyRisks'        => 'Debt leverage and customer concentration.',
                'suggestedStrategy'=> '<span class="material-symbols-outlined" style="font-size:12px;vertical-align:middle;color:var(--yellow);">warning</span> Level 1 Basic: Cash-Secured Put at $150',
            ],
            [
                'symbol'          => 'CRWD',
                'name'            => 'CrowdStrike Holdings Inc',
                'sector'          => 'Software',
                'price'           => 268.50,
                'targetPrice'     => 335.00,
                'score'           => 81,
                'risk'            => 'MED',
                'revGrowth'       => '+31.4%',
                'marketCap'       => '$65B',
                'reasoning'       => 'Falcon XDR platform consolidating enterprise cybersecurity spend.',
                'catalysts'       => 'Cloud security module expansion, identity protection.',
                'keyRisks'        => 'Outage reputational impact.',
                'suggestedStrategy'=> '<span class="material-symbols-outlined" style="font-size:12px;vertical-align:middle;color:var(--green);">check_circle</span> Level 1 Basic: Long Call 5% OTM Target $280',
            ],
        ];

        return $this->json(['status' => 'success', 'source' => 'curated_fallback', 'data' => $suggestions]);
    }
}
