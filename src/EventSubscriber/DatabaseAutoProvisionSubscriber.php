<?php

namespace App\EventSubscriber;

use App\Service\DatabaseBootstrapService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class DatabaseAutoProvisionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private DatabaseBootstrapService $bootstrap,
    ) {}

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

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 100],
        ];
    }
}
