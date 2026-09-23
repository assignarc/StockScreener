<?php

namespace App\Controller;

use App\Repository\StockRepository;
use App\Service\BrokerManagerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * BrokerController
 *
 * REST API controller for broker management, OAuth login/callback lifecycle,
 * multi-broker portfolio aggregation, transaction history, and option chain discovery.
 */
#[Route('/api/broker', name: 'api_broker_')]
class BrokerController extends AbstractController
{
    /**
     * Initializes the broker controller.
     *
     * @param BrokerManagerService $brokerManager Multi-broker management service.
     * @param StockRepository      $stockRepository Stock repository for equity profiles.
     * @param \App\Service\AdvisorService $advisorService Investment advisor intelligence service.
     * @param \App\Service\TaxEngine $taxEngine Tax calculation engine.
     */
    public function __construct(
        private BrokerManagerService $brokerManager,
        private StockRepository $stockRepository,
        private \App\Service\AdvisorService $advisorService,
        private \App\Service\TaxEngine $taxEngine
    ) {}

    /**
     * Returns a list of all configured broker instances and their authorization status.
     *
     * @return JsonResponse JSON list of broker instances.
     */
    #[Route('/list', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $list = [];
        foreach ($this->brokerManager->getBrokers() as $id => $broker) {
            $list[] = [
                'id'         => $id,
                'type'       => $broker->getType(),
                'nickname'   => $broker->getNickname(),
                'configured' => $broker->isConfigured(),
                'authorized' => $broker->isAuthorized(),
            ];
        }
        return $this->json(['status' => 'success', 'brokers' => $list]);
    }

    /**
     * Returns the aggregated portfolio across all active and authorized broker accounts.
     *
     * @return JsonResponse Aggregated portfolio data.
     */
    #[Route('/portfolio/aggregated', name: 'portfolio_aggregated', methods: ['GET'])]
    public function aggregatedPortfolio(): JsonResponse
    {
        return $this->json($this->brokerManager->getAggregatedPortfolio());
    }

    /**
     * Returns actionable advisor insights and daily nuggets (Tax Savings, Income, Growth).
     *
     * @return JsonResponse Advisor recommendations.
     */
    #[Route('/advisor/insights', name: 'advisor_insights', methods: ['GET'])]
    public function advisorInsights(): JsonResponse
    {
        $portfolio = $this->brokerManager->getAggregatedPortfolio();
        $history = $this->brokerManager->getAggregatedHistory(365);
        $taxReport = $this->taxEngine->calculateTaxRealizations($history, $portfolio);
        $insights = $this->advisorService->generateActionableInsights($portfolio, ['unrealized_lots' => $taxReport]);

        return $this->json([
            'status' => 'success',
            'data' => $insights,
        ]);
    }

    /**
     * Returns aggregated open orders across all active brokers.
     *
     * @param Request $request HTTP request.
     * @return JsonResponse Aggregated open orders.
     */
    #[Route('/orders/aggregated', name: 'orders_aggregated', methods: ['GET'])]
    public function aggregatedOpenOrders(Request $request): JsonResponse
    {
        $force = filter_var($request->query->get('force'), FILTER_VALIDATE_BOOLEAN);
        return $this->json([
            'status' => 'success',
            'data' => $this->brokerManager->getAggregatedOpenOrders($force),
        ]);
    }

    /**
     * Returns the configuration, authorization, and operational status of a specific broker instance.
     *
     * @param string $id Broker identifier.
     * @return JsonResponse Broker status metrics.
     */
    #[Route('/{id}/status', name: 'status', methods: ['GET'])]
    public function status(string $id): JsonResponse
    {
        $broker = $this->brokerManager->getBroker($id);
        if (!$broker) {
            return $this->json(['error' => "Broker instance '$id' not found"], 404);
        }

        return $this->json([
            'id'             => $broker->getId(),
            'type'           => $broker->getType(),
            'nickname'       => $broker->getNickname(),
            'configured'     => $broker->isConfigured(),
            'authorized'     => $broker->isAuthorized(),
            'tradingEnabled' => $broker->isTradingEnabled(),
            'mode'           => $broker->isTradingEnabled()
                ? 'READ-WRITE (Trading Allowed)'
                : 'READ-ONLY (Trading Disabled)',
            'message'        => $broker->isAuthorized()
                ? $broker->getNickname() . ' connected & authorized'
                : ($broker->isConfigured()
                    ? $broker->getNickname() . ' configured. Authorization required.'
                    : $broker->getNickname() . ' credentials missing.'),
        ]);
    }

    /**
     * Initiates OAuth authentication flow for a given broker instance.
     *
     * @param string  $id      Broker identifier.
     * @param Request $request HTTP request.
     * @return Response Redirect to broker authorization URL or error response.
     */
    #[Route('/{id}/login', name: 'login', methods: ['GET'])]
    public function login(string $id, Request $request): Response
    {
        $broker = $this->brokerManager->getBroker($id);
        if (!$broker) {
            return new Response('<h1>Broker Not Found</h1><p>Broker instance ' . htmlspecialchars($id) . ' does not exist.</p>', 404);
        }

        $redirectUri = $this->buildCallbackUri($request, $id);
        $nonce       = bin2hex(random_bytes(16));
        $state       = $id . ':' . $nonce;

        if ($request->hasSession()) {
            $request->getSession()->set('oauth_state_' . $id, $state);
        }

        $authUrl = $broker->getAuthUrl($redirectUri, $state);
        if (!$authUrl) {
            return new Response('<h1>OAuth Not Supported</h1><p>This broker type (' . htmlspecialchars($broker->getType()) . ') does not use OAuth login.</p>', 400);
        }

        return $this->redirect($authUrl);
    }

    /**
     * Handles OAuth authorization code callback from the broker provider.
     *
     * @param string  $id      Broker identifier.
     * @param Request $request HTTP request.
     * @return Response Rendered callback view or error response.
     */
    #[Route('/{id}/callback', name: 'callback', methods: ['GET'])]
    public function callback(string $id, Request $request): Response
    {
        $broker = $this->brokerManager->getBroker($id);
        if (!$broker) {
            return new Response('<h1>Broker Not Found</h1><p>Broker instance ' . htmlspecialchars($id) . ' does not exist.</p>', 404);
        }

        $code  = $request->query->get('code');
        $state = $request->query->get('state');
        $error = $request->query->get('error') ?? $request->query->get('error_description');

        if ($error) {
            return new Response('<h1>Authorization Error</h1><p>' . htmlspecialchars($error) . '</p>', 400);
        }

        if ($request->hasSession()) {
            $sessionState = $request->getSession()->get('oauth_state_' . $id);
            $request->getSession()->remove('oauth_state_' . $id);

            if (empty($state) || empty($sessionState) || !hash_equals($sessionState, $state)) {
                return new Response('<h1>Security Verification Failed (CSRF)</h1><p>Invalid state nonce. Please re-initiate login.</p>', 403);
            }
        }

        if (!$code) {
            return new Response('<h1>Missing Authorization Code</h1>', 400);
        }

        $redirectUri = $this->buildCallbackUri($request, $id);
        $result      = $broker->exchangeAuthCode($code, $redirectUri);

        if (isset($result['error'])) {
            return new Response('<h1>Token Exchange Failed</h1><p>' . htmlspecialchars($result['error']) . '</p>', 400);
        }

        return $this->render('screener/broker_callback.html.twig');
    }

    /**
     * Returns portfolio balances and positions for a specific broker.
     *
     * @param string $id Broker identifier.
     * @return JsonResponse Broker portfolio payload.
     */
    #[Route('/{id}/portfolio', name: 'portfolio', methods: ['GET'])]
    public function portfolio(string $id): JsonResponse
    {
        $broker = $this->brokerManager->getBroker($id);
        if (!$broker) {
            return $this->json(['error' => "Broker instance '$id' not found"], 404);
        }

        return $this->json([
            'status' => 'success',
            'data'   => $broker->getAccountPortfolio(),
        ]);
    }

    /**
     * Returns aggregated transaction history across all configured brokers.
     *
     * @param Request $request HTTP request containing days filter and force refresh flags.
     * @return JsonResponse Aggregated transaction history and cash flow metrics.
     */
    #[Route('/history/aggregated', name: 'history_aggregated', methods: ['GET'])]
    public function aggregatedHistory(Request $request): JsonResponse
    {
        $periodParam = strtoupper(trim((string) $request->query->get('period', $request->query->get('days', '30'))));
        $force = $request->query->getBoolean('force') || $request->query->getBoolean('forceRefresh');

        $today = new \DateTimeImmutable();
        $currentYear = (int) $today->format('Y');

        if ($periodParam === 'THIS_YEAR' || $periodParam === 'YTD') {
            $startDate = $currentYear . '-01-01';
            $days = max(1, (int) $today->diff(new \DateTimeImmutable($startDate))->format('%a')) + 10;
            $history = $this->brokerManager->getAggregatedHistory($days, $force);
            $history = array_values(array_filter($history, fn($tx) => substr($tx['date'] ?? '', 0, 4) === (string)$currentYear));
        } elseif ($periodParam === 'LAST_YEAR') {
            $lastYear = $currentYear - 1;
            $startDate = $lastYear . '-01-01';
            $days = max(1, (int) $today->diff(new \DateTimeImmutable($startDate))->format('%a')) + 15;
            $history = $this->brokerManager->getAggregatedHistory($days, $force);
            $history = array_values(array_filter($history, fn($tx) => substr($tx['date'] ?? '', 0, 4) === (string)$lastYear));
        } elseif ($periodParam === 'ALL') {
            $days = 3650;
            $history = $this->brokerManager->getAggregatedHistory($days, $force);
        } else {
            $cleanNum = (int) preg_replace('/\D/', '', $periodParam);
            $days = $cleanNum > 0 ? min(3650, $cleanNum) : 30;
            $history = $this->brokerManager->getAggregatedHistory($days, $force);
            $cutoff = $today->modify("-{$days} days")->format('Y-m-d');
            $history = array_values(array_filter($history, fn($tx) => ($tx['date'] ?? '') >= $cutoff));
        }

        $totalDividends = 0.0;
        $totalPremiums = 0.0;
        $netCashImpact = 0.0;

        foreach ($history as $tx) {
            $amt = (float) ($tx['amount'] ?? 0.0);
            $cat = $tx['category'] ?? strtoupper($tx['type'] ?? '');
            if ($cat === 'DIVIDEND' && $amt > 0) {
                $totalDividends += $amt;
            } elseif ($cat === 'OPTION' && $amt > 0) {
                $totalPremiums += $amt;
            }
            $netCashImpact += $amt;
        }

        return $this->json([
            'status' => 'success',
            'data'   => [
                'summary' => [
                    'totalDividends'    => $totalDividends,
                    'totalPremiums'     => $totalPremiums,
                    'netCashImpact'     => $netCashImpact,
                    'totalTransactions' => count($history),
                ],
                'transactions' => $history,
            ],
        ]);
    }

    /**
     * Returns transaction history for a specific broker instance.
     *
     * @param string  $id      Broker identifier.
     * @param Request $request HTTP request containing days filter.
     * @return JsonResponse Transaction history payload.
     */
    #[Route('/{id}/history', name: 'history', methods: ['GET'])]
    public function history(string $id, Request $request): JsonResponse
    {
        $broker = $this->brokerManager->getBroker($id);
        if (!$broker) {
            return $this->json(['error' => "Broker instance '$id' not found"], 404);
        }

        $periodParam = strtoupper(trim((string) $request->query->get('period', $request->query->get('days', '30'))));
        $today = new \DateTimeImmutable();
        $currentYear = (int) $today->format('Y');

        if ($periodParam === 'THIS_YEAR' || $periodParam === 'YTD') {
            $startDate = $currentYear . '-01-01';
            $days = max(1, (int) $today->diff(new \DateTimeImmutable($startDate))->format('%a')) + 10;
            $history = $broker->getAccountHistory($days);
            $history = array_values(array_filter($history, fn($tx) => substr($tx['date'] ?? '', 0, 4) === (string)$currentYear));
        } elseif ($periodParam === 'LAST_YEAR') {
            $lastYear = $currentYear - 1;
            $startDate = $lastYear . '-01-01';
            $days = max(1, (int) $today->diff(new \DateTimeImmutable($startDate))->format('%a')) + 15;
            $history = $broker->getAccountHistory($days);
            $history = array_values(array_filter($history, fn($tx) => substr($tx['date'] ?? '', 0, 4) === (string)$lastYear));
        } elseif ($periodParam === 'ALL') {
            $days = 3650;
            $history = $broker->getAccountHistory($days);
        } else {
            $cleanNum = (int) preg_replace('/\D/', '', $periodParam);
            $days = $cleanNum > 0 ? min(3650, $cleanNum) : 30;
            $history = $broker->getAccountHistory($days);
            $cutoff = $today->modify("-{$days} days")->format('Y-m-d');
            $history = array_values(array_filter($history, fn($tx) => ($tx['date'] ?? '') >= $cutoff));
        }

        $normalized = array_map([BrokerManagerService::class, 'normalizeTransaction'], $history);

        $totalDividends = 0.0;
        $totalPremiums = 0.0;
        $netCashImpact = 0.0;

        foreach ($normalized as $tx) {
            $amt = (float) ($tx['amount'] ?? 0.0);
            $cat = $tx['category'] ?? strtoupper($tx['type'] ?? '');
            if ($cat === 'DIVIDEND' && $amt > 0) {
                $totalDividends += $amt;
            } elseif ($cat === 'OPTION' && $amt > 0) {
                $totalPremiums += $amt;
            }
            $netCashImpact += $amt;
        }

        return $this->json([
            'status' => 'success',
            'data'   => [
                'summary' => [
                    'totalDividends'    => $totalDividends,
                    'totalPremiums'     => $totalPremiums,
                    'netCashImpact'     => $netCashImpact,
                    'totalTransactions' => count($normalized),
                ],
                'transactions' => $normalized,
            ],
        ]);
    }

    /**
     * Returns option chain contracts for a given ticker and broker.
     *
     * @param string $id     Broker identifier.
     * @param string $symbol Target stock ticker.
     * @return JsonResponse Option chain strikes and expirations.
     */
    #[Route('/{id}/option-chain/{symbol}', name: 'option_chain', methods: ['GET'])]
    public function optionChain(string $id, string $symbol): JsonResponse
    {
        $stock        = $this->stockRepository->findOneBy(['symbol' => strtoupper($symbol)]);
        $currentPrice = $stock ? ($stock->getPrice() ?? 100.0) : 100.0;

        $chain = $this->brokerManager->getOptionChain($symbol, $currentPrice, $id);

        return $this->json([
            'status' => 'success',
            'data'   => $chain,
        ]);
    }

    /**
     * Constructs the canonical OAuth redirect URI for this host and broker instance.
     *
     * @param Request $request HTTP request.
     * @param string  $id      Broker identifier.
     * @return string Canonical callback URL.
     */
    private function buildCallbackUri(Request $request, string $id): string
    {
        $scheme = $request->getScheme();
        $host = $request->getHttpHost();
        if (empty($host) || $host === ':') {
            $host = '127.0.0.1:8000';
        }
        return $scheme . '://' . $host . '/api/broker/' . $id . '/callback';
    }
}
