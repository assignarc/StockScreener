<?php

namespace App\Controller;

use App\Repository\StockRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use App\Service\BrokerManagerService;

class ScreenerController extends AbstractController
{
    public function __construct(
        private StockRepository $stockRepository,
        private BrokerManagerService $brokerManager
    ) {}

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
}

