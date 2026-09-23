<?php

namespace App\EventSubscriber;

use App\Service\DatabaseBootstrapService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * DatabaseAutoProvisionSubscriber
 *
 * Kernel request event subscriber that guarantees Just-In-Time (JIT) SQLite schema
 * provisioning and redirects unconfigured new installations to the /setup wizard.
 */
class DatabaseAutoProvisionSubscriber implements EventSubscriberInterface
{
    /**
     * Initializes the subscriber with the database bootstrap service.
     *
     * @param DatabaseBootstrapService $bootstrap Database bootstrap and schema provisioning service.
     */
    public function __construct(
        private DatabaseBootstrapService $bootstrap,
    ) {}

    /**
     * Handles kernel request events to ensure tables exist and redirect to setup if needed.
     *
     * @param RequestEvent $event Kernel request event.
     * @return void
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // Just-In-Time provision database and tables
        $this->bootstrap->ensureSchemaAndSeed();

        $request = $event->getRequest();
        $path    = $request->getPathInfo();

        // Allow static assets, setup routes, API routes, or settings
        if (
            str_starts_with($path, '/setup') ||
            str_starts_with($path, '/api/setup') ||
            str_starts_with($path, '/settings') ||
            str_starts_with($path, '/api/') ||
            str_starts_with($path, '/css/') ||
            str_starts_with($path, '/js/') ||
            str_starts_with($path, '/favicon')
        ) {
            return;
        }

        // If first-time setup is not completed and Finnhub key is empty, guide user to /setup
        if (!$this->bootstrap->isSetupCompleted() && $path === '/') {
            // Optional redirect or banner on first visit
            $event->setResponse(new RedirectResponse('/setup'));
        }
    }

    /**
     * Registers subscribed kernel events and priority rankings.
     *
     * @return array Map of event names to handler methods.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 100],
        ];
    }
}
