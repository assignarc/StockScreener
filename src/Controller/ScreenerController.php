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

/**
 * ScreenerController
 *
 * Main UI view controller rendering dashboard, screener, portfolio, tax center,
 * historical performance charts, discover recommendations, planner, and engine monitor.
 */
class ScreenerController extends AbstractController
{
    /**
     * Initializes the screener view controller.
     *
     * @param StockRepository           $stockRepository    Stock repository.
     * @param BrokerManagerService      $brokerManager      Multi-broker management service.
     * @param PersistentCacheService    $cache              Persistent cache service.
     * @param TaxEngine                 $taxEngine          Tax calculation engine.
     * @param PerformanceHistoryService $performanceHistory Portfolio snapshot and growth curve service.
     */
    public function __construct(
        private StockRepository $stockRepository,
        private BrokerManagerService $brokerManager,
        private PersistentCacheService $cache,
        private TaxEngine $taxEngine,
        private PerformanceHistoryService $performanceHistory,
    ) {}

    /**
     * Renders the portfolio growth history page with interactive charts.
     *
     * @return Response Rendered view template.
     */
    #[Route('/portfolio/history', name: 'app_portfolio_history')]
    public function portfolioHistory(): Response
    {
        $historyData = $this->performanceHistory->getGrowthHistory('THIS_YEAR');

        return $this->render('screener/history.html.twig', [
            'growth' => $historyData,
            'activePage' => 'portfolio_history',
        ]);
    }

    /**
     * API endpoint returning portfolio growth curves and benchmark series for a given period.
     *
     * @param Request $request HTTP request containing period parameter.
     * @return JsonResponse JSON growth data.
     */
    #[Route('/portfolio/history-api', name: 'app_portfolio_history_api', methods: ['GET'])]
    public function portfolioHistoryApi(Request $request): JsonResponse
    {
        $period = $request->query->get('period', 'THIS_YEAR');
        $historyData = $this->performanceHistory->getGrowthHistory($period);

        return $this->json($historyData);
    }

    /**
     * Handles file upload and processing of Schwab transaction CSV files.
     *
     * @param Request                                $request     HTTP request containing uploaded file.
     * @param \App\Service\SchwabCsvImporterService $csvImporter CSV importer service.
     * @return JsonResponse Import result summary.
     */
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

    /**
     * Renders the Tax Center view showing capital gains/losses, holding terms, and estimated liability.
     *
     * @return Response Rendered view template.
     */
    #[Route('/portfolio/tax', name: 'app_portfolio_tax')]
    public function taxCenter(): Response
    {
        $portfolioData = $this->brokerManager->getAggregatedPortfolio();
        
        // Fetch up to 10 years of history for tax calculation to ensure complete lot matching
        $history = $this->brokerManager->getAggregatedHistory(3650);
        $realizations = $this->taxEngine->calculateTaxRealizations($history, $portfolioData);
        $incomeRecords = $this->taxEngine->calculateIncomeRealizations($history, $portfolioData);

        $currentYear = (int) date('Y');
        $carryforwards = $this->taxEngine->calculateHistoricalLossCarryforwards($realizations, $currentYear);
        $taxLiability = $this->taxEngine->calculateTaxLiability($realizations, $incomeRecords, $carryforwards, null, (string)$currentYear);

        return $this->render('screener/tax_center.html.twig', [
            'portfolio' => $portfolioData,
            'realizations' => $realizations,
            'incomeRecords' => $incomeRecords,
            'taxLiability' => $taxLiability,
            'carryforwards' => $carryforwards,
            'history' => $history,
            'activePage' => 'tax_center',
        ]);
    }

    /**
     * Renders the main dashboard page.
     *
     * @return Response Rendered view template.
     */
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

    /**
     * Renders the stock screener page with customizable filter controls.
     *
     * @return Response Rendered view template.
     */
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

    /**
     * Renders the portfolio management page.
     *
     * @return Response Rendered view template.
     */
    #[Route('/portfolio', name: 'app_portfolio')]
    public function portfolio(): Response
    {
        $portfolioData = $this->brokerManager->getAggregatedPortfolio();

        return $this->render('screener/portfolio.html.twig', [
            'portfolio' => $portfolioData,
            'activePage' => 'portfolio',
        ]);
    }

    /**
     * Renders the discover ideas page with AI-generated recommendations.
     *
     * @return Response Rendered view template.
     */
    #[Route('/discover', name: 'app_discover')]
    public function discover(): Response
    {
        $stocks = $this->stockRepository->findByFilters();

        return $this->render('screener/discover.html.twig', [
            'totalStocks' => count($stocks),
            'activePage' => 'discover',
        ]);
    }

    /**
     * Renders the Capital Flywheel trade planner page.
     *
     * @return Response Rendered view template.
     */
    #[Route('/planner', name: 'app_planner')]
    public function planner(): Response
    {
        $portfolioData = $this->brokerManager->getAggregatedPortfolio();

        return $this->render('screener/planner.html.twig', [
            'portfolio' => $portfolioData,
            'activePage' => 'planner',
        ]);
    }

    /**
     * Renders the application help and documentation page.
     *
     * @return Response Rendered view template.
     */
    #[Route('/help', name: 'app_help')]
    public function help(): Response
    {
        return $this->render('screener/help.html.twig', [
            'activePage' => 'help',
        ]);
    }

    /**
     * Renders the background Flywheel engine health and telemetry monitor.
     *
     * @return Response Rendered view template.
     */
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

