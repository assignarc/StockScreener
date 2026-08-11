<?php

namespace App\Controller;

use App\Service\AppConfigService;
use App\Service\DatabaseBootstrapService;
use App\Service\FinnhubService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SetupController extends AbstractController
{
    public function __construct(
        private AppConfigService $appConfig,
        private DatabaseBootstrapService $bootstrap,
        private FinnhubService $finnhubService,
        private HttpClientInterface $httpClient,
    ) {}

    #[Route('/setup', name: 'app_setup', methods: ['GET'])]
    public function setupView(): Response
    {
        $status = $this->bootstrap->getSchemaStatus();

        return $this->render('screener/setup.html.twig', [
            'activePage'      => 'setup',
            'schemaStatus'    => $status,
            'config'          => $this->appConfig->getAll(),
            'finnhubKey'      => $this->appConfig->getFinnhubApiKey(),
            'geminiKey'       => $this->appConfig->get('gemini.api_key'),
            'brokerInstances' => $this->appConfig->getBrokerInstances(),
            'setupCompleted'  => $this->appConfig->isSetupCompleted(),
        ]);
    }

    #[Route('/api/setup/test-finnhub', name: 'api_setup_test_finnhub', methods: ['POST'])]
    public function testFinnhubKey(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $key     = trim($payload['apiKey'] ?? '');

        if (empty($key)) {
            return $this->json(['status' => 'error', 'message' => 'API key cannot be empty'], 400);
        }

        try {
            $response = $this->httpClient->request('GET', 'https://finnhub.io/api/v1/quote', [
                'query' => [
                    'symbol' => 'AAPL',
                    'token'  => $key,
                ],
            ]);

            if ($response->getStatusCode() === 200) {
                $data = $response->toArray();
                if (isset($data['c']) && $data['c'] > 0) {
                    return $this->json([
                        'status'  => 'success',
                        'message' => "Finnhub API key is valid! Verified live AAPL quote: \${$data['c']}",
                        'price'   => $data['c'],
                    ]);
                }
            }

            return $this->json([
                'status'  => 'error',
                'message' => 'Finnhub returned an unexpected response. Please check your API key.',
            ], 400);
        } catch (\Throwable $e) {
            return $this->json([
                'status'  => 'error',
                'message' => 'Finnhub validation failed: ' . $e->getMessage(),
            ], 400);
        }
    }

    #[Route('/api/setup/save', name: 'api_setup_save', methods: ['POST'])]
    public function saveSetup(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['status' => 'error', 'message' => 'Invalid JSON payload'], 400);
        }

        // Save API Keys & Broker settings directly into SQLite app_config
        if (isset($payload['finnhub_api_key'])) {
            $this->appConfig->set('finnhub.api_key', trim($payload['finnhub_api_key']));
        }
        if (isset($payload['llm_provider'])) {
            $this->appConfig->set('llm.provider', trim($payload['llm_provider']));
        }
        if (isset($payload['gemini_api_key'])) {
            $this->appConfig->set('gemini.api_key', trim($payload['gemini_api_key']));
        }
        if (isset($payload['gemini_model'])) {
            $this->appConfig->set('gemini.model', trim($payload['gemini_model']));
        }
        if (isset($payload['openai_api_key'])) {
            $this->appConfig->set('openai.api_key', trim($payload['openai_api_key']));
        }
        if (isset($payload['openai_model'])) {
            $this->appConfig->set('openai.model', trim($payload['openai_model']));
        }
        if (isset($payload['claude_api_key'])) {
            $this->appConfig->set('claude.api_key', trim($payload['claude_api_key']));
        }
        if (isset($payload['claude_model'])) {
            $this->appConfig->set('claude.model', trim($payload['claude_model']));
        }
        if (isset($payload['local_llm_url'])) {
            $this->appConfig->set('local_llm.url', trim($payload['local_llm_url']));
        }
        if (isset($payload['local_llm_api_key'])) {
            $this->appConfig->set('local_llm.api_key', trim($payload['local_llm_api_key']));
        }
        if (isset($payload['local_llm_model'])) {
            $this->appConfig->set('local_llm.model', trim($payload['local_llm_model']));
        }
        if (isset($payload['broker_instances']) && is_array($payload['broker_instances'])) {
            $this->appConfig->saveBrokerInstances($payload['broker_instances']);
        }

        $markComplete = $payload['mark_completed'] ?? true;
        if ($markComplete) {
            $this->appConfig->markSetupCompleted(true);
        }

        return $this->json([
            'status'  => 'success',
            'message' => 'Setup configurations saved successfully in SQLite data.db!',
            'schema'  => $this->bootstrap->getSchemaStatus(),
        ]);
    }

    #[Route('/api/setup/status', name: 'api_setup_status', methods: ['GET'])]
    public function getStatus(): JsonResponse
    {
        $instances = $this->appConfig->getBrokerInstances();
        $isAnyConfigured = false;
        foreach ($instances as $inst) {
            if (!empty($inst['app_key'])) {
                $isAnyConfigured = true;
                break;
            }
        }

        return $this->json([
            'status' => 'success',
            'data'   => [
                'schema'         => $this->bootstrap->getSchemaStatus(),
                'finnhubConfig'  => !empty($this->appConfig->getFinnhubApiKey()),
                'schwabConfig'   => $isAnyConfigured,
                'setupCompleted' => $this->appConfig->isSetupCompleted(),
            ],
        ]);
    }
}
