<?php

namespace App\Controller;

use App\Repository\StockRepository;
use App\Service\BrokerManagerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/broker', name: 'api_broker_')]
class BrokerController extends AbstractController
{
    public function __construct(
        private BrokerManagerService $brokerManager,
        private StockRepository $stockRepository
    ) {}

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

    #[Route('/portfolio/aggregated', name: 'portfolio_aggregated', methods: ['GET'])]
    public function aggregatedPortfolio(): JsonResponse
    {
        return $this->json($this->brokerManager->getAggregatedPortfolio());
    }

    #[Route('/orders/aggregated', name: 'orders_aggregated', methods: ['GET'])]
    public function aggregatedOpenOrders(Request $request): JsonResponse
    {
        $force = filter_var($request->query->get('force'), FILTER_VALIDATE_BOOLEAN);
        return $this->json([
            'status' => 'success',
            'data' => $this->brokerManager->getAggregatedOpenOrders($force),
        ]);
    }

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

    #[Route('/{id}/login', name: 'login', methods: ['GET'])]
    public function login(string $id, Request $request): Response
    {
        $broker = $this->brokerManager->getBroker($id);
        if (!$broker) {
            return new Response('<h1>❌ Broker Not Found</h1><p>Broker instance ' . htmlspecialchars($id) . ' does not exist.</p>', 404);
        }

        $redirectUri = $this->buildCallbackUri($request, $id);
        $nonce       = bin2hex(random_bytes(16));
        $state       = $id . ':' . $nonce;

        if ($request->hasSession()) {
            $request->getSession()->set('oauth_state_' . $id, $state);
        }

        $authUrl = $broker->getAuthUrl($redirectUri, $state);
        if (!$authUrl) {
            return new Response('<h1>⚠️ OAuth Not Supported</h1><p>This broker type (' . htmlspecialchars($broker->getType()) . ') does not use OAuth login.</p>', 400);
        }

        return $this->redirect($authUrl);
    }

    #[Route('/{id}/callback', name: 'callback', methods: ['GET'])]
    public function callback(string $id, Request $request): Response
    {
        $broker = $this->brokerManager->getBroker($id);
        if (!$broker) {
            return new Response('<h1>❌ Broker Not Found</h1><p>Broker instance ' . htmlspecialchars($id) . ' does not exist.</p>', 404);
        }

        $code  = $request->query->get('code');
        $state = $request->query->get('state');
        $error = $request->query->get('error') ?? $request->query->get('error_description');

        if ($error) {
            return new Response('<h1>❌ Authorization Error</h1><p>' . htmlspecialchars($error) . '</p>', 400);
        }

        if ($request->hasSession()) {
            $sessionState = $request->getSession()->get('oauth_state_' . $id);
            $request->getSession()->remove('oauth_state_' . $id);

            if (empty($state) || empty($sessionState) || !hash_equals($sessionState, $state)) {
                return new Response('<h1>🛡️ Security Verification Failed (CSRF)</h1><p>Invalid state nonce. Please re-initiate login.</p>', 403);
            }
        }

        if (!$code) {
            return new Response('<h1>⚠️ Missing Authorization Code</h1>', 400);
        }

        $redirectUri = $this->buildCallbackUri($request, $id);
        $result      = $broker->exchangeAuthCode($code, $redirectUri);

        if (isset($result['error'])) {
            return new Response('<h1>❌ Token Exchange Failed</h1><p>' . htmlspecialchars($result['error']) . '</p>', 400);
        }

        return $this->render('screener/broker_callback.html.twig');
    }

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

    #[Route('/history/aggregated', name: 'history_aggregated', methods: ['GET'])]
    public function aggregatedHistory(Request $request): JsonResponse
    {
        $days = max(1, min(180, (int) $request->query->get('days', 30)));
        $force = $request->query->getBoolean('force') || $request->query->getBoolean('forceRefresh');
        $history = $this->brokerManager->getAggregatedHistory($days, $force);

        $totalDividends = 0.0;
        $totalPremiums = 0.0;
        $netCashImpact = 0.0;

        foreach ($history as $tx) {
            $amt = (float) ($tx['amount'] ?? 0.0);
            $type = strtoupper($tx['type'] ?? '');
            if ($type === 'DIVIDEND') {
                $totalDividends += $amt;
            } elseif (in_array($type, ['OPTION', 'OPTION_PREMIUM', 'PREMIUM'])) {
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

    #[Route('/{id}/history', name: 'history', methods: ['GET'])]
    public function history(string $id, Request $request): JsonResponse
    {
        $broker = $this->brokerManager->getBroker($id);
        if (!$broker) {
            return $this->json(['error' => "Broker instance '$id' not found"], 404);
        }

        $days = max(1, min(180, (int) $request->query->get('days', 30)));
        $history = $broker->getAccountHistory($days);

        $totalDividends = 0.0;
        $totalPremiums = 0.0;
        $netCashImpact = 0.0;

        foreach ($history as $tx) {
            $amt = (float) ($tx['amount'] ?? 0.0);
            $type = strtoupper($tx['type'] ?? '');
            if ($type === 'DIVIDEND') {
                $totalDividends += $amt;
            } elseif (in_array($type, ['OPTION', 'OPTION_PREMIUM', 'PREMIUM'])) {
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
