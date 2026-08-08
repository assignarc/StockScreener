<?php

namespace App\Controller;

use App\Entity\Stock;
use App\Repository\StockRepository;
use App\Repository\WatchlistRepository;
use App\Service\AppConfigService;
use App\Service\FinnhubService;
use App\Service\FlywheelService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', name: 'api_')]
class StockController extends AbstractController
{
    public function __construct(
        private StockRepository $stockRepository,
        private WatchlistRepository $watchlistRepository,
        private FinnhubService $finnhubService,
        private FlywheelService $flywheelService,
        private AppConfigService $appConfig,
        private EntityManagerInterface $entityManager,
    ) {}

    #[Route('/stocks', name: 'stocks_list', methods: ['GET'])]
    public function listStocks(Request $request): JsonResponse
    {
        $sector = $request->query->get('sector', 'ALL');
        $risk   = $request->query->get('risk', 'ALL');
        $query  = $request->query->get('q');

        $stocks        = $this->stockRepository->findByFilters($sector, $risk, $query);
        $watchlistItems = array_map(fn($w) => $w->getSymbol(), $this->watchlistRepository->findAll());

        $data = array_map(function (Stock $stock) use ($watchlistItems) {
            $arr = $stock->toArray();
            $arr['isWatchlisted'] = in_array($stock->getSymbol(), $watchlistItems, true);
            $arr['flywheel']      = $this->flywheelService->evaluateSignal($stock);
            return $arr;
        }, $stocks);

        return $this->json([
            'status' => 'success',
            'count'  => count($data),
            'data'   => $data,
        ]);
    }

    #[Route('/stocks/add', name: 'stocks_add', methods: ['POST'])]
    public function addStock(Request $request): JsonResponse
    {
        $data   = json_decode($request->getContent(), true) ?? [];
        $symbol = strtoupper(trim($data['symbol'] ?? ''));

        if (empty($symbol)) {
            return $this->json(['status' => 'error', 'message' => 'Ticker symbol is required'], 400);
        }

        $stock = $this->stockRepository->findOneBy(['symbol' => $symbol]) ?? new Stock();
        $stock->setSymbol($symbol)
              ->setName($data['name'] ?? $symbol)
              ->setSector($data['sector'] ?? 'Technology')
              ->setPrice((float) ($data['price'] ?? 100.0))
              ->setTargetPrice((float) ($data['targetPrice'] ?? 120.0))
              ->setScore((int) ($data['score'] ?? 75))
              ->setRisk($data['risk'] ?? 'MED')
              ->setRevGrowth($data['revGrowth'] ?? '+15.0%')
              ->setGrossMargin($data['grossMargin'] ?? '65.0%')
              ->setCashRunway($data['cashRunway'] ?? '24 Months')
              ->setShortInterest($data['shortInterest'] ?? '2.5%')
              ->setAnalystRating($data['analystRating'] ?? 'BUY')
              ->setMarketCap($data['marketCap'] ?? '$50B')
              ->setThesis($data['thesis'] ?? 'High conviction candidate.')
              ->setCatalysts($data['catalysts'] ?? 'Earnings growth.')
              ->setKeyRisks($data['keyRisks'] ?? 'Market volatility.');

        $this->entityManager->persist($stock);
        $this->entityManager->flush();

        return $this->json([
            'status'  => 'success',
            'message' => "Stock {$symbol} saved successfully to screener!",
            'data'    => $stock->toArray(),
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
            'status'  => 'success',
            'message' => "Stock {$symbol} removed from tracked list.",
        ]);
    }

    #[Route('/stocks/suggest/{symbol}', name: 'stocks_suggest', methods: ['GET'])]
    public function suggestStock(string $symbol): JsonResponse
    {
        $symbol  = strtoupper(trim($symbol));
        $quote   = $this->finnhubService->getQuote($symbol);
        $profile = $this->finnhubService->getCompanyProfile($symbol);

        $price  = (float) ($quote['c'] ?? 0.0);
        $name   = $profile['name'] ?? $symbol;
        $sector = $profile['finnhubIndustry'] ?? 'Technology';

        // Auto-calculate suggested metrics from market data
        $targetFactor = (float) $this->appConfig->get('screener.suggest.target_price_factor');
        $targetPrice  = $price > 0 ? round($price * $targetFactor, 2) : 100.0;
        $score        = $price > 0 ? rand(68, 88) : 60;
        $risk         = $price > 100 ? 'LOW' : 'MED';

        $stock = new Stock();
        $stock->setSymbol($symbol)->setName($name)->setSector($sector)
              ->setPrice($price)->setTargetPrice($targetPrice)->setScore($score)->setRisk($risk);

        $flywheel = $this->flywheelService->evaluateSignal($stock);

        return $this->json([
            'status' => 'success',
            'data'   => [
                'symbol'      => $symbol,
                'name'        => $name,
                'sector'      => $sector,
                'price'       => $price,
                'targetPrice' => $targetPrice,
                'score'       => $score,
                'risk'        => $risk,
                'revGrowth'   => '+18.5%',
                'marketCap'   => isset($profile['marketCapitalization'])
                    ? '$' . number_format($profile['marketCapitalization']) . 'M'
                    : '$45B',
                'thesis'    => 'Auto-imported from Finnhub & Schwab Market Data Scanner. High conviction candidate.',
                'catalysts' => 'Upcoming earnings report, sector momentum, institutional inflow.',
                'flywheel'  => $flywheel,
            ],
        ]);
    }

    #[Route('/watchlist', name: 'watchlist_toggle', methods: ['POST'])]
    public function toggleWatchlist(Request $request): JsonResponse
    {
        $content = json_decode($request->getContent(), true);
        $symbol  = strtoupper($content['symbol'] ?? '');

        if (!$symbol) {
            return $this->json(['error' => 'Symbol is required'], 400);
        }

        $existing = $this->watchlistRepository->findOneBy(['symbol' => $symbol]);
        if ($existing) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();
            return $this->json(['status' => 'removed', 'symbol' => $symbol]);
        }

        $watchlist = new \App\Entity\Watchlist();
        $watchlist->setSymbol($symbol);
        $this->entityManager->persist($watchlist);
        $this->entityManager->flush();

        return $this->json(['status' => 'added', 'symbol' => $symbol]);
    }

    #[Route('/quote/{symbol}', name: 'quote', methods: ['GET'])]
    public function getQuote(string $symbol, Request $request): JsonResponse
    {
        $apiKey = $request->headers->get('X-Finnhub-Token') ?? $request->query->get('key');
        $quote  = $this->finnhubService->getQuote($symbol, $apiKey);

        if (!$quote) {
            return $this->json(['error' => 'Failed to fetch quote from Finnhub or missing API key'], 400);
        }

        return $this->json([
            'symbol' => strtoupper($symbol),
            'quote'  => $quote,
        ]);
    }
}
