<?php

namespace App\Controller;

use App\Repository\StockRepository;
use App\Service\SchwabService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/schwab', name: 'api_schwab_')]
class SchwabController extends AbstractController
{
    public function __construct(
        private SchwabService $schwabService,
        private StockRepository $stockRepository,
    ) {}

    #[Route('/status', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return $this->json([
            'configured'     => $this->schwabService->isConfigured(),
            'authorized'     => $this->schwabService->isAuthorized(),
            'tradingEnabled' => $this->schwabService->isTradingEnabled(),
            'mode'           => $this->schwabService->isTradingEnabled()
                ? 'READ-WRITE (Trading Allowed)'
                : 'READ-ONLY (Trading Disabled)',
            'message'        => $this->schwabService->isAuthorized()
                ? 'Charles Schwab account connected & authorized'
                : ($this->schwabService->isConfigured()
                    ? 'Schwab Developer credentials detected. Account authorization required.'
                    : 'Schwab credentials missing in .env'),
        ]);
    }

    #[Route('/login', name: 'login', methods: ['GET'])]
    public function login(Request $request): Response
    {
        $redirectUri = $this->buildCallbackUri($request);
        $authUrl     = $this->schwabService->getAuthUrl($redirectUri);
        return $this->redirect($authUrl);
    }

    #[Route('/callback', name: 'callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        $code  = $request->query->get('code');
        $error = $request->query->get('error') ?? $request->query->get('error_description');

        if ($error) {
            return new Response(
                '<h1>❌ Schwab Authorization Error</h1><p>' . htmlspecialchars($error) . '</p>',
                400
            );
        }

        if (!$code) {
            return new Response(
                '<h1>⚠️ Missing Authorization Code</h1><p>No authorization code was passed by Schwab.</p>',
                400
            );
        }

        $redirectUri = $this->buildCallbackUri($request);
        $result      = $this->schwabService->exchangeAuthCode($code, $redirectUri);

        if (isset($result['error'])) {
            return new Response(
                '<h1>❌ Token Exchange Failed</h1><p>' . htmlspecialchars($result['error']) . '</p>'
                . '<p>Check redirect_uri matches Schwab Developer App: <code>' . htmlspecialchars($redirectUri) . '</code></p>',
                400
            );
        }

        return $this->render('screener/schwab_callback.html.twig');
    }

    #[Route('/portfolio', name: 'portfolio', methods: ['GET'])]
    public function portfolio(): JsonResponse
    {
        return $this->json([
            'status' => 'success',
            'data'   => $this->schwabService->getAccountPortfolio(),
        ]);
    }

    #[Route('/history', name: 'history', methods: ['GET'])]
    public function history(Request $request): JsonResponse
    {
        $days = max(1, min(180, (int) $request->query->get('days', 30)));
        return $this->json([
            'status' => 'success',
            'data'   => $this->schwabService->getAccountHistory($days),
        ]);
    }

    #[Route('/option-chain/{symbol}', name: 'option_chain', methods: ['GET'])]
    public function optionChain(string $symbol): JsonResponse
    {
        $stock        = $this->stockRepository->findOneBy(['symbol' => strtoupper($symbol)]);
        $currentPrice = $stock ? ($stock->getPrice() ?? 100.0) : 100.0;
        $chain        = $this->schwabService->getOptionChain($symbol, $currentPrice);

        return $this->json([
            'status' => 'success',
            'data'   => $chain,
        ]);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function buildCallbackUri(Request $request): string
    {
        return $request->getScheme() . '://' . $request->getHttpHost() . '/api/schwab/callback';
    }
}
