<?php

namespace App\Controller;

use App\Service\AppConfigService;
use App\Service\PersistentCacheService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ConfigController
 *
 * Web and API controller for application settings management,
 * broker configuration, and persistent cache inspection / eviction.
 */
class ConfigController extends AbstractController
{
    /**
     * Initializes the config controller.
     *
     * @param AppConfigService       $appConfig Application configuration service.
     * @param PersistentCacheService $cache     Persistent cache service.
     */
    public function __construct(
        private AppConfigService $appConfig,
        private PersistentCacheService $cache,
    ) {}

    /**
     * Renders the settings web UI page.
     *
     * @return Response Rendered settings page template.
     */
    #[Route('/settings', name: 'app_settings')]
    public function settings(): Response
    {
        return $this->render('screener/settings.html.twig', [
            'activePage'      => 'settings',
            'config'          => $this->appConfig->getAll(),
            'brokerInstances' => $this->appConfig->getBrokerInstances(),
            'cacheStats'      => $this->cache->getStats(),
        ]);
    }

    /**
     * Retrieves all active application configuration keys and broker instance definitions.
     *
     * @return JsonResponse JSON configuration payload.
     */
    #[Route('/api/config', name: 'api_config_get', methods: ['GET'])]
    public function getConfig(): JsonResponse
    {
        return $this->json([
            'status' => 'success',
            'data'   => $this->appConfig->getAll(),
            'broker_instances' => $this->appConfig->getBrokerInstances(),
        ]);
    }

    /**
     * Saves application configuration keys and broker instances into SQLite.
     *
     * @param Request $request HTTP request containing key-value configurations.
     * @return JsonResponse JSON response confirming save status.
     */
    #[Route('/api/config', name: 'api_config_save', methods: ['POST'])]
    public function saveConfig(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['status' => 'error', 'message' => 'Invalid JSON payload'], 400);
        }

        if (isset($data['broker_instances']) && is_array($data['broker_instances'])) {
            $this->appConfig->saveBrokerInstances($data['broker_instances']);
            unset($data['broker_instances']);
        }

        $this->appConfig->save($data);

        return $this->json([
            'status'  => 'success',
            'message' => 'Configuration saved successfully.',
            'data'    => $this->appConfig->getAll(),
            'broker_instances' => $this->appConfig->getBrokerInstances(),
        ]);
    }

    /**
     * Retrieves persistent cache size and entry statistics.
     *
     * @return JsonResponse JSON cache statistics.
     */
    #[Route('/api/config/cache/stats', name: 'api_cache_stats', methods: ['GET'])]
    public function getCacheStats(): JsonResponse
    {
        return $this->json([
            'status' => 'success',
            'data'   => $this->cache->getStats(),
        ]);
    }

    /**
     * Clears persistent cache entries by namespace prefix or entirely.
     *
     * @param Request $request HTTP request containing cache clear type parameter.
     * @return JsonResponse JSON response with count of cleared entries and updated stats.
     */
    #[Route('/api/config/cache/clear', name: 'api_cache_clear', methods: ['POST'])]
    public function clearCache(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $type    = $payload['type'] ?? 'all';

        $cleared = match ($type) {
            'finnhub' => $this->cache->clearPrefix('finnhub'),
            'broker', 'schwab' => $this->cache->clearPrefix('broker'),
            default   => $this->cache->clearAll(),
        };

        return $this->json([
            'status'  => 'success',
            'message' => "Cleared {$cleared} persistent cache entries.",
            'stats'   => $this->cache->getStats(),
        ]);
    }

    /**
     * Checks if the user has accepted the legal disclaimer within the last 24 hours.
     *
     * @return JsonResponse Status indicating whether disclaimer has been accepted and is active.
     */
    #[Route('/api/disclaimer/status', name: 'api_disclaimer_status', methods: ['GET'])]
    public function getDisclaimerStatus(): JsonResponse
    {
        $acceptedAt = $this->cache->get('user.disclaimer.accepted_at', ttlSeconds: 86400);
        $isAccepted = ($acceptedAt !== null && $acceptedAt !== false);

        return $this->json([
            'status' => 'success',
            'accepted' => $isAccepted,
            'acceptedAt' => $acceptedAt,
            'expiresInSeconds' => $isAccepted ? 86400 : 0,
        ]);
    }

    /**
     * Records the user's explicit acceptance of the legal disclaimer with 24-hour expiration.
     *
     * @param Request $request HTTP request.
     * @return JsonResponse Confirmation response.
     */
    #[Route('/api/disclaimer/accept', name: 'api_disclaimer_accept', methods: ['POST'])]
    public function acceptDisclaimer(Request $request): JsonResponse
    {
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        // Persist acceptance in SQLite persistent cache for exactly 24 hours (86,400 seconds)
        $this->cache->set('user.disclaimer.accepted_at', $now, ttlSeconds: 86400);

        return $this->json([
            'status' => 'success',
            'message' => 'Legal disclaimer accepted successfully.',
            'acceptedAt' => $now,
            'ttlSeconds' => 86400,
        ]);
    }
}
