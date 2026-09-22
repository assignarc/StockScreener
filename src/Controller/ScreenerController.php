<?php

namespace App\Controller;

use App\Repository\StockRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use App\Service\BrokerManagerService;
use App\Service\PersistentCacheService;

use App\Service\TaxEngine;

use App\Service\PerformanceHistoryService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ScreenerController extends AbstractController
{
    public function __construct(
        private StockRepository $stockRepository,
        private BrokerManagerService $brokerManager,
        private PersistentCacheService $cache,
        private TaxEngine $taxEngine,
        private PerformanceHistoryService $performanceHistory,
    ) {}

    #[Route('/portfolio/history', name: 'app_portfolio_history')]
    public function portfolioHistory(): Response
    {
        $historyData = $this->performanceHistory->getGrowthHistory('6M');

        return $this->render('screener/history.html.twig', [
            'growth' => $historyData,
            'activePage' => 'portfolio_history',
        ]);
    }

    #[Route('/portfolio/history-api', name: 'app_portfolio_history_api', methods: ['GET'])]
    public function portfolioHistoryApi(Request $request): JsonResponse
    {
        $period = $request->query->get('period', '6M');
        $historyData = $this->performanceHistory->getGrowthHistory($period);

        return $this->json($historyData);
    }

    #[Route('/portfolio/import-csv', name: 'app_portfolio_import_csv', methods: ['POST'])]
    public function importCsv(Request $request, \App\Service\SchwabCsvImporterService $csvImporter): JsonResponse
    {
        $file = $request->files->get('csv_file');
        if (!$file) {
            return $this->json(['error' => 'No CSV file uploaded'], 400);
        }

        $account = $request->request->get('account_number', 'V-Brokerage');
        $result = $csvImporter->importCsv($file->getPathname(), $account);

        return $this->json($result);
    }

    #[Route('/portfolio/tax', name: 'app_portfolio_tax')]
    public function taxCenter(): Response
    {
        $portfolioData = $this->brokerManager->getAggregatedPortfolio();
        
        // Fetch 2 years of history for tax calculation
        $history = $this->brokerManager->getAggregatedHistory(730);
        $realizations = $this->taxEngine->calculateTaxRealizations($history, $portfolioData);

        return $this->render('screener/tax_center.html.twig', [
            'portfolio' => $portfolioData,
            'realizations' => $realizations,
            'history' => $history,
            'activePage' => 'tax_center',
        ]);
    }

    #[Route('/', name: 'app_dashboard')]
    public function dashboard(): Response
    {
        $stocks = $this->stockRepository->findByFilters();
        $portfolioData = $this->brokerManager->getAggregatedPortfolio();

        return $this->render('screener/dashboard.html.twig', [
            'totalStocks' => count($stocks),
            'portfolio' => $portfolioData,
            'activePage' => 'dashboard',
        ]);
    }

    #[Route('/screener', name: 'app_screener')]
    public function screener(): Response
    {
        $stocks = $this->stockRepository->findByFilters();
        
        $sectors = array_unique(array_map(fn($s) => $s->getSector(), $stocks));
        sort($sectors);

        return $this->render('screener/screener.html.twig', [
            'totalStocks' => count($stocks),
            'sectors' => $sectors,
            'activePage' => 'screener',
        ]);
    }

    #[Route('/portfolio', name: 'app_portfolio')]
    public function portfolio(): Response
    {
        $portfolioData = $this->brokerManager->getAggregatedPortfolio();

        return $this->render('screener/portfolio.html.twig', [
            'portfolio' => $portfolioData,
            'activePage' => 'portfolio',
        ]);
    }

    #[Route('/discover', name: 'app_discover')]
    public function discover(): Response
    {
        $stocks = $this->stockRepository->findByFilters();

        return $this->render('screener/discover.html.twig', [
            'totalStocks' => count($stocks),
            'activePage' => 'discover',
        ]);
    }

    #[Route('/planner', name: 'app_planner')]
    public function planner(): Response
    {
        $portfolioData = $this->brokerManager->getAggregatedPortfolio();

        return $this->render('screener/planner.html.twig', [
            'portfolio' => $portfolioData,
            'activePage' => 'planner',
        ]);
    }

    #[Route('/help', name: 'app_help')]
    public function help(): Response
    {
        return $this->render('screener/help.html.twig', [
            'activePage' => 'help',
        ]);
    }
    #[Route('/engine-monitor', name: 'app_engine_monitor')]
    public function engineMonitor(): Response
    {
        $cachedLandscape = $this->cache->get('flywheel.engine.landscape', isSensitive: true);

        return $this->render('screener/engine_monitor.html.twig', [
            'landscape' => $cachedLandscape,
            'activePage' => 'engine_monitor',
        ]);
    }
}

