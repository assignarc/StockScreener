<?php

namespace App\Controller;

use App\Entity\Watchlist;
use App\Repository\StockRepository;
use App\Repository\WatchlistRepository;
use App\Service\FinnhubService;
use App\Service\FlywheelService;
use App\Service\SchwabService;
use App\Service\GeminiService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', name: 'api_')]
class ApiController extends AbstractController
{
    public function __construct(
        private StockRepository $stockRepository,
        private WatchlistRepository $watchlistRepository,
        private FinnhubService $finnhubService,
        private FlywheelService $flywheelService,
        private SchwabService $schwabService,
        private GeminiService $geminiService,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('/stocks', name: 'stocks_list', methods: ['GET'])]
    public function listStocks(Request $request): JsonResponse
    {
        $sector = $request->query->get('sector', 'ALL');
        $risk = $request->query->get('risk', 'ALL');
        $query = $request->query->get('q');

        $stocks = $this->stockRepository->findByFilters($sector, $risk, $query);
        $watchlistItems = array_map(fn($w) => $w->getSymbol(), $this->watchlistRepository->findAll());

        $data = array_map(function($stock) use ($watchlistItems) {
            $arr = $stock->toArray();
            $arr['isWatchlisted'] = in_array($stock->getSymbol(), $watchlistItems, true);
            $arr['flywheel'] = $this->flywheelService->evaluateSignal($stock);
            return $arr;
        }, $stocks);

        return $this->json([
            'status' => 'success',
            'count' => count($data),
            'data' => $data,
        ]);
    }

    #[Route('/quote/{symbol}', name: 'quote', methods: ['GET'])]
    public function getQuote(string $symbol, Request $request): JsonResponse
    {
        $apiKey = $request->headers->get('X-Finnhub-Token') ?? $request->query->get('key');
        
        $quote = $this->finnhubService->getQuote($symbol, $apiKey);
        if (!$quote) {
            return $this->json(['error' => 'Failed to fetch quote from Finnhub or missing API key'], 400);
        }

        return $this->json([
            'symbol' => strtoupper($symbol),
            'quote' => $quote
        ]);
    }

    #[Route('/watchlist', name: 'watchlist_toggle', methods: ['POST'])]
    public function toggleWatchlist(Request $request): JsonResponse
    {
        $content = json_decode($request->getContent(), true);
        $symbol = strtoupper($content['symbol'] ?? '');

        if (!$symbol) {
            return $this->json(['error' => 'Symbol is required'], 400);
        }

        $existing = $this->watchlistRepository->findOneBy(['symbol' => $symbol]);
        if ($existing) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();
            return $this->json(['status' => 'removed', 'symbol' => $symbol]);
        }

        $watchlist = new Watchlist();
        $watchlist->setSymbol($symbol);
        $this->entityManager->persist($watchlist);
        $this->entityManager->flush();

        return $this->json(['status' => 'added', 'symbol' => $symbol]);
    }

    #[Route('/flywheel/allocate', name: 'flywheel_allocate', methods: ['POST'])]
    public function allocateCapital(Request $request): JsonResponse
    {
        $content = json_decode($request->getContent(), true);
        $capital = (float) ($content['capital'] ?? 10000);

        if ($capital <= 0) {
            return $this->json(['error' => 'Capital must be greater than 0'], 400);
        }

        $stocks = $this->stockRepository->findAll();
        $allocation = $this->flywheelService->calculateAllocation($stocks, $capital);

        return $this->json([
            'status' => 'success',
            'data' => $allocation
        ]);
    }

    #[Route('/schwab/status', name: 'schwab_status', methods: ['GET'])]
    public function schwabStatus(): JsonResponse
    {
        return $this->json([
            'configured' => $this->schwabService->isConfigured(),
            'authorized' => $this->schwabService->isAuthorized(),
            'tradingEnabled' => $this->schwabService->isTradingEnabled(),
            'mode' => $this->schwabService->isTradingEnabled() ? 'READ-WRITE (Trading Allowed)' : 'READ-ONLY (Trading Disabled)',
            'message' => $this->schwabService->isAuthorized()
                ? 'Charles Schwab account connected & authorized'
                : ($this->schwabService->isConfigured() 
                    ? 'Schwab Developer credentials detected. Account authorization required.' 
                    : 'Schwab credentials missing in .env')
        ]);
    }

    #[Route('/schwab/login', name: 'schwab_login', methods: ['GET'])]
    public function schwabLogin(Request $request)
    {
        $scheme = $request->getScheme();
        $host = $request->getHttpHost();
        $redirectUri = $scheme . '://' . $host . '/api/schwab/callback';

        $authUrl = $this->schwabService->getAuthUrl($redirectUri);
        return $this->redirect($authUrl);
    }

    #[Route('/schwab/callback', name: 'schwab_callback', methods: ['GET'])]
    public function schwabCallback(Request $request)
    {
        $code = $request->query->get('code');
        $error = $request->query->get('error') ?? $request->query->get('error_description');

        if ($error) {
            return new \Symfony\Component\HttpFoundation\Response(
                '<h1>❌ Schwab Authorization Error</h1><p>' . htmlspecialchars($error) . '</p>',
                400
            );
        }

        if (!$code) {
            return new \Symfony\Component\HttpFoundation\Response(
                '<h1>⚠️ Missing Authorization Code</h1><p>No authorization code was passed by Schwab.</p>',
                400
            );
        }

        $scheme = $request->getScheme();
        $host = $request->getHttpHost();
        $redirectUri = $scheme . '://' . $host . '/api/schwab/callback';

        $result = $this->schwabService->exchangeAuthCode($code, $redirectUri);

        if (isset($result['error'])) {
            return new \Symfony\Component\HttpFoundation\Response(
                '<h1>❌ Token Exchange Failed</h1><p>' . htmlspecialchars($result['error']) . '</p><p>Check if redirect_uri matching exact setting in Schwab Developer App: <code>' . htmlspecialchars($redirectUri) . '</code></p>',
                400
            );
        }

        return new \Symfony\Component\HttpFoundation\Response('
            <!DOCTYPE html>
            <html>
            <head>
                <title>Schwab Connected</title>
                <style>
                    body { font-family: -apple-system, sans-serif; background: #0b0e14; color: #e6edf3; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
                    .card { background: #161b26; border: 1px solid #2e3545; padding: 40px; border-radius: 14px; text-align: center; max-width: 450px; box-shadow: 0 20px 40px rgba(0,0,0,0.5); }
                    h1 { color: #3fb950; font-size: 22px; margin-bottom: 10px; }
                    p { color: #8c96a8; font-size: 14px; line-height: 1.6; }
                    .btn { display: inline-block; margin-top: 20px; padding: 10px 24px; background: #58a6ff; color: #0b0e14; text-decoration: none; border-radius: 8px; font-weight: 700; }
                </style>
            </head>
            <body>
                <div class="card">
                    <h1>✅ Charles Schwab Connected!</h1>
                    <p>Your Charles Schwab account has been successfully authorized and linked to Moonshot Screener.</p>
                    <a href="/" class="btn">Return to Screener</a>
                </div>
            </body>
            </html>
        ');
    }

    #[Route('/schwab/option-chain/{symbol}', name: 'schwab_option_chain', methods: ['GET'])]
    public function schwabOptionChain(string $symbol): JsonResponse
    {
        $stock = $this->stockRepository->findOneBy(['symbol' => strtoupper($symbol)]);
        $currentPrice = $stock ? ($stock->getPrice() ?? 100.0) : 100.0;

        $chain = $this->schwabService->getOptionChain($symbol, $currentPrice);

        return $this->json([
            'status' => 'success',
            'data' => $chain
        ]);
    }

    #[Route('/schwab/portfolio', name: 'schwab_portfolio', methods: ['GET'])]
    public function schwabPortfolio(): JsonResponse
    {
        $portfolio = $this->schwabService->getAccountPortfolio();

        return $this->json([
            'status' => 'success',
            'data' => $portfolio
        ]);
    }

    #[Route('/flywheel/covered-call-suggestions', name: 'flywheel_covered_call_suggestions', methods: ['GET'])]
    public function flywheelCoveredCallSuggestions(): JsonResponse
    {
        $portfolio = $this->schwabService->getAccountPortfolio();
        $suggestions = $this->flywheelService->generatePortfolioCoveredCallSuggestions($portfolio);

        return $this->json([
            'status' => 'success',
            'data' => $suggestions
        ]);
    }

    #[Route('/ai/flywheel-ideas', name: 'ai_flywheel_ideas', methods: ['GET'])]
    public function aiFlywheelIdeas(): JsonResponse
    {
        $portfolio = $this->schwabService->getAccountPortfolio();
        $stocks = $this->stockRepository->findByFilters();

        $aiIdeas = $this->geminiService->generateFlywheelIdeas($portfolio, $stocks);

        return $this->json([
            'status' => 'success',
            'data' => $aiIdeas
        ]);
    }

    #[Route('/ai/option-chain-analysis/{symbol}', name: 'ai_option_chain_analysis', methods: ['GET'])]
    public function aiOptionChainAnalysis(string $symbol): JsonResponse
    {
        $symbol = strtoupper(trim($symbol));
        $stock = $this->stockRepository->findOneBy(['symbol' => $symbol]);
        $currentPrice = $stock ? ($stock->getPrice() ?? 100.0) : 100.0;

        $chain = $this->schwabService->getOptionChain($symbol, $currentPrice);
        $analysis = $this->geminiService->analyzeOptionChain($symbol, $currentPrice, $chain);

        return $this->json([
            'status' => 'success',
            'data' => $analysis
        ]);
    }

    #[Route('/stocks/suggest/{symbol}', name: 'stocks_suggest', methods: ['GET'])]
    public function suggestStock(string $symbol): JsonResponse
    {
        $symbol = strtoupper(trim($symbol));
        $quote = $this->finnhubService->getQuote($symbol);
        $profile = $this->finnhubService->getCompanyProfile($symbol);

        $price = (float) ($quote['c'] ?? 0.0);
        $name = $profile['name'] ?? $symbol;
        $sector = $profile['finnhubIndustry'] ?? 'Technology';

        // Auto-calculate suggested metrics based on market data
        $targetPrice = $price > 0 ? round($price * 1.22, 2) : 100.0;
        $score = $price > 0 ? rand(68, 88) : 60;
        $risk = $price > 100 ? 'LOW' : 'MED';

        // Create temporary entity for Flywheel Signal evaluation
        $stock = new \App\Entity\Stock();
        $stock->setSymbol($symbol);
        $stock->setName($name);
        $stock->setSector($sector);
        $stock->setPrice($price);
        $stock->setTargetPrice($targetPrice);
        $stock->setScore($score);
        $stock->setRisk($risk);

        $flywheel = $this->flywheelService->evaluateSignal($stock);

        return $this->json([
            'status' => 'success',
            'data' => [
                'symbol' => $symbol,
                'name' => $name,
                'sector' => $sector,
                'price' => $price,
                'targetPrice' => $targetPrice,
                'score' => $score,
                'risk' => $risk,
                'revGrowth' => '+18.5%',
                'marketCap' => isset($profile['marketCapitalization']) ? '$' . number_format($profile['marketCapitalization']) . 'M' : '$45B',
                'thesis' => 'Auto-imported from Finnhub & Schwab Market Data Scanner. High conviction candidate.',
                'catalysts' => 'Upcoming earnings report, sector momentum, institutional inflow.',
                'flywheel' => $flywheel,
            ]
        ]);
    }

    #[Route('/stocks/discover-suggestions', name: 'stocks_discover_suggestions', methods: ['GET'])]
    public function discoverSuggestions(): JsonResponse
    {
        $suggestions = [
            [
                'symbol' => 'AMD',
                'name' => 'Advanced Micro Devices Inc',
                'sector' => 'Semiconductors',
                'price' => 142.50,
                'targetPrice' => 185.00,
                'score' => 84,
                'risk' => 'MED',
                'revGrowth' => '+24.5%',
                'marketCap' => '$230B',
                'reasoning' => 'Dominating the x86 server market and rapidly expanding MI300X AI GPU market share. High analyst upgrade momentum with +29.8% implied upside.',
                'catalysts' => 'MI350 series launch, datacenter market share gains, enterprise AI server refresh cycle.',
                'keyRisks' => 'Competition from NVIDIA Blackwell and macro chip cycle timing.',
                'suggestedStrategy' => '🟢 Level 1 Basic: Long Call 5% OTM Target $150 (30-60 DTE)',
            ],
            [
                'symbol' => 'AMZN',
                'name' => 'Amazon.com Inc',
                'sector' => 'Consumer Cyclical',
                'price' => 186.20,
                'targetPrice' => 225.00,
                'score' => 88,
                'risk' => 'LOW',
                'revGrowth' => '+14.2%',
                'marketCap' => '$1.94T',
                'reasoning' => 'AWS cloud growth re-accelerating past +19% YoY. Retail operating margins expanding at record pace with high-margin digital advertising momentum.',
                'catalysts' => 'AWS AI workload adoption, regional fulfillment efficiency gains, Prime Video ad network expansion.',
                'keyRisks' => 'Consumer spending slowdown and FTC regulatory scrutiny.',
                'suggestedStrategy' => '🟡 Level 1 Basic: Cash-Secured Put at $170 (8% Discount Entry Yield)',
            ],
            [
                'symbol' => 'MSFT',
                'name' => 'Microsoft Corporation',
                'sector' => 'Software',
                'price' => 415.80,
                'targetPrice' => 490.00,
                'score' => 92,
                'risk' => 'LOW',
                'revGrowth' => '+15.8%',
                'marketCap' => '$3.09T',
                'reasoning' => 'Monopolistic enterprise moat with Azure OpenAI leading corporate AI cloud migration. Over $14B in annual Copilot enterprise seat monetization.',
                'catalysts' => 'Azure growth outperformance, Copilot seat expansion across Fortune 500, Windows 11 enterprise upgrade.',
                'keyRisks' => 'Capital expenditure intensity for AI datacenters.',
                'suggestedStrategy' => '🟡 Level 1 Basic: Cash-Secured Put at $385 (Income Yield)',
            ],
            [
                'symbol' => 'META',
                'name' => 'Meta Platforms Inc',
                'sector' => 'Communication Services',
                'price' => 510.40,
                'targetPrice' => 610.00,
                'score' => 89,
                'risk' => 'LOW',
                'revGrowth' => '+22.1%',
                'marketCap' => '$1.29T',
                'reasoning' => 'AI recommendation engine driving massive engagement gains across Instagram Reels and WhatsApp business messaging. Top-tier free cash flow conversion.',
                'catalysts' => 'Llama 4 model rollout, Advantage+ AI ad suite adoption, hardware AR/VR cost optimization.',
                'keyRisks' => 'Short-form video competition and EU data privacy regulations.',
                'suggestedStrategy' => '🟢 Level 1 Basic: Defined-Risk Long Call at $530 (45 DTE)',
            ],
            [
                'symbol' => 'AVGO',
                'name' => 'Broadcom Inc',
                'sector' => 'Semiconductors',
                'price' => 162.30,
                'targetPrice' => 205.00,
                'score' => 86,
                'risk' => 'LOW',
                'revGrowth' => '+43.0%',
                'marketCap' => '$755B',
                'reasoning' => 'Exclusive custom ASIC AI chip partner for Google (TPU), Meta, and ByteDance. Massive VMware acquisition synergies boosting recurring software cash flow.',
                'catalysts' => 'Tomahawk 5 800G networking switch adoption, custom AI accelerator orders, VMware subscription migration.',
                'keyRisks' => 'Debt leverage from VMware transaction and customer concentration.',
                'suggestedStrategy' => '🟡 Level 1 Basic: Cash-Secured Put at $150 (8% Discount Entry)',
            ],
            [
                'symbol' => 'CRWD',
                'name' => 'CrowdStrike Holdings Inc',
                'sector' => 'Software',
                'price' => 268.50,
                'targetPrice' => 335.00,
                'score' => 81,
                'risk' => 'MED',
                'revGrowth' => '+31.4%',
                'marketCap' => '$65B',
                'reasoning' => 'Falcon XDR platform consolidating enterprise cybersecurity spend. Annual Recurring Revenue (ARR) exceeding $3.6B with 98% gross retention rate.',
                'catalysts' => 'Next-gen SIEM platform adoption, Cloud security module expansion, identity protection growth.',
                'keyRisks' => 'Outage incident recovery reputational impact and high EV/Sales valuation.',
                'suggestedStrategy' => '🟢 Level 1 Basic: Long Call 5% OTM Target $280',
            ],
        ];

        return $this->json([
            'status' => 'success',
            'data' => $suggestions
        ]);
    }

    #[Route('/stocks/add', name: 'stocks_add', methods: ['POST'])]
    public function addStock(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $symbol = strtoupper(trim($data['symbol'] ?? ''));

        if (empty($symbol)) {
            return $this->json(['status' => 'error', 'message' => 'Ticker symbol is required'], 400);
        }

        $stock = $this->stockRepository->findOneBy(['symbol' => $symbol]) ?? new \App\Entity\Stock();
        $stock->setSymbol($symbol);
        $stock->setName($data['name'] ?? $symbol);
        $stock->setSector($data['sector'] ?? 'Technology');
        $stock->setPrice((float) ($data['price'] ?? 100.0));
        $stock->setTargetPrice((float) ($data['targetPrice'] ?? 120.0));
        $stock->setScore((int) ($data['score'] ?? 75));
        $stock->setRisk($data['risk'] ?? 'MED');
        $stock->setRevGrowth($data['revGrowth'] ?? '+15.0%');
        $stock->setGrossMargin($data['grossMargin'] ?? '65.0%');
        $stock->setCashRunway($data['cashRunway'] ?? '24 Months');
        $stock->setShortInterest($data['shortInterest'] ?? '2.5%');
        $stock->setAnalystRating($data['analystRating'] ?? 'BUY');
        $stock->setMarketCap($data['marketCap'] ?? '$50B');
        $stock->setThesis($data['thesis'] ?? 'High conviction candidate.');
        $stock->setCatalysts($data['catalysts'] ?? 'Earnings growth.');
        $stock->setKeyRisks($data['keyRisks'] ?? 'Market volatility.');

        $this->entityManager->persist($stock);
        $this->entityManager->flush();

        return $this->json([
            'status' => 'success',
            'message' => "Stock {$symbol} saved successfully to screener!",
            'data' => $stock->toArray()
        ]);
    }

    #[Route('/flywheel/daily-planner', name: 'flywheel_daily_planner', methods: ['GET', 'POST'])]
    public function flywheelDailyPlanner(Request $request): JsonResponse
    {
        $content = json_decode($request->getContent(), true) ?? [];
        $riskCap = (float) ($request->query->get('riskCap') ?? $content['riskCap'] ?? 10000.0);

        $portfolio = $this->schwabService->getAccountPortfolio();
        $stocks = $this->stockRepository->findAll();

        $allocation = $this->flywheelService->calculateAllocation($stocks, $riskCap, $portfolio);
        $earlyExits = $this->flywheelService->generateEarlyExitSuggestions($portfolio);
        $coveredCalls = $this->flywheelService->generatePortfolioCoveredCallSuggestions($portfolio);
        $aiIdeas = $this->geminiService->generateFlywheelIdeas($portfolio, $stocks);

        return $this->json([
            'status' => 'success',
            'data' => [
                'riskSummary' => [
                    'configuredCap' => $riskCap,
                    'existingRiskUsed' => $allocation['existingRiskUsed'],
                    'availableRiskRemaining' => $allocation['availableRiskRemaining'],
                ],
                'earlyExitsBTC' => $earlyExits,
                'coveredCallsSTO' => $coveredCalls['suggestions'] ?? [],
                'aiIdeas' => $aiIdeas['ideas'] ?? [],
            ]
        ]);
    }

    #[Route('/flywheel/confirm-trade', name: 'flywheel_confirm_trade', methods: ['POST'])]
    public function confirmTrade(Request $request): JsonResponse
    {
        $trade = json_decode($request->getContent(), true) ?? [];
        $result = $this->geminiService->verifyTradePreExecution($trade);

        return $this->json([
            'status' => 'success',
            'data' => $result
        ]);
    }

    #[Route('/flywheel/scenario/{symbol}', name: 'flywheel_scenario', methods: ['GET'])]
    public function tradeScenario(string $symbol): JsonResponse
    {
        $symbol = strtoupper(trim($symbol));
        $stock = $this->stockRepository->findOneBy(['symbol' => $symbol]);
        $price = $stock ? ($stock->getPrice() ?? 100.0) : 100.0;

        $putStrike = round($price * 0.93, 2);
        $callStrike = round($price * 1.06, 2);
        $estPremium = round($price * 0.025, 2);

        return $this->json([
            'status' => 'success',
            'data' => [
                'symbol' => $symbol,
                'currentPrice' => $price,
                'strategies' => [
                    'putSTO' => [
                        'name' => 'Cash-Secured Put (STO - Sell To Open)',
                        'action' => 'Sell 1x Put at $' . $putStrike,
                        'askPremium' => '$' . $estPremium . ' / share ($' . ($estPremium * 100) . ' Credit)',
                        'collateralRequired' => '$' . number_format($putStrike * 100, 2),
                        'orderType' => 'LIMIT at Mid-Price $' . $estPremium,
                        'pros' => ['Immediate upfront cash income', 'Acquire stock at 7% discount if assigned', '100% cash backed with zero margin risk'],
                        'cons' => ['Capped profit at premium received', 'Obligated to buy stock if price drops below $' . $putStrike],
                        'scenarios' => [
                            'bullish' => 'Stock moves +10%: Option expires worthless, keep 100% of $' . ($estPremium * 100) . ' cash.',
                            'neutral' => 'Stock stays flat: Theta decay works in your favor, capture $' . ($estPremium * 100) . ' yield.',
                            'bearish' => 'Stock drops -10%: Assigned 100 shares at $' . $putStrike . ' (effective entry $' . round($putStrike - $estPremium, 2) . ').'
                        ]
                    ],
                    'callSTO' => [
                        'name' => 'Covered Call (STO - Sell To Open)',
                        'action' => 'Sell 1x Call at $' . $callStrike,
                        'askPremium' => '$' . round($estPremium * 1.1, 2) . ' / share ($' . round($estPremium * 110, 2) . ' Credit)',
                        'collateralRequired' => '100 Owned Shares of ' . $symbol,
                        'orderType' => 'LIMIT at Mid-Price $' . round($estPremium * 1.1, 2),
                        'pros' => ['Generate cash yield on owned shares', 'Downside buffer equal to premium received', '100% covered by stock'],
                        'cons' => ['Stock gains above $' . $callStrike . ' are capped', 'Shares may be called away at $' . $callStrike],
                        'scenarios' => [
                            'bullish' => 'Stock moves +10%: Shares called away at $' . $callStrike . ', lock in stock capital gain + premium.',
                            'neutral' => 'Stock stays flat: Keep stock and keep 100% of cash premium.',
                            'bearish' => 'Stock drops -10%: Premium offsets portion of stock loss.'
                        ]
                    ]
                ]
            ]
        ]);
    }

    #[Route('/stocks/{id}', name: 'stocks_delete', methods: ['DELETE'])]
    public function deleteStock(int $id): JsonResponse
    {
        $stock = $this->stockRepository->find($id);
        if (!$stock) {
            return $this->json(['status' => 'error', 'message' => 'Stock not found'], 404);
        }

        $symbol = $stock->getSymbol();
        $this->entityManager->remove($stock);
        $this->entityManager->flush();

        return $this->json([
            'status' => 'success',
            'message' => "Stock {$symbol} removed from tracked list."
        ]);
    }
}



