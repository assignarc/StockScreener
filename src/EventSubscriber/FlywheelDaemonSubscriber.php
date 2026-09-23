<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * FlywheelDaemonSubscriber
 *
 * Ensures that the background Flywheel AI Engine daemon starts automatically
 * when the server receives web traffic, avoiding the need for manual system crons.
 */
class FlywheelDaemonSubscriber implements EventSubscriberInterface
{
    private static bool $checkedThisProcess = false;

    /**
     * Initializes the subscriber with the kernel instance.
     *
     * @param KernelInterface $kernel Symfony application kernel.
     */
    public function __construct(
        private KernelInterface $kernel,
    ) {}

    /**
     * Checks if the flywheel daemon is running on incoming main requests.
     *
     * @param RequestEvent $event Kernel request event.
     * @return void
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || self::$checkedThisProcess) {
            return;
        }

        self::$checkedThisProcess = true;

        $path = $event->getRequest()->getPathInfo();
        if (
            str_starts_with($path, '/css/') ||
            str_starts_with($path, '/js/') ||
            str_starts_with($path, '/favicon')
        ) {
            return;
        }

        $this->ensureDaemonRunning();
    }

    /**
     * Inspects the engine lock file to determine if a background daemon process is alive.
     * Spawns a new daemon if the lock is unheld.
     *
     * @return void
     */
    private function ensureDaemonRunning(): void
    {
        $lockFile = sys_get_temp_dir() . '/flywheel_engine.lock';

        // Check if the lock file exists and if another process holds the lock
        if (file_exists($lockFile)) {
            $handle = @fopen($lockFile, 'r+');
            if ($handle) {
                // If we CAN acquire the lock, it means no active daemon is holding it
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    flock($handle, LOCK_UN);
                    fclose($handle);
                    $this->spawnDaemon();
                } else {
                    // Lock is held by an active running daemon
                    fclose($handle);
                }
                return;
            }
        }

        // Lock file doesn't exist yet, spawn the daemon
        $this->spawnDaemon();
    }

    /**
     * Spawns the Flywheel engine console command in a detached background process.
     *
     * @return void
     */
    private function spawnDaemon(): void
    {
        $projectDir = $this->kernel->getProjectDir();
        $command = "php {$projectDir}/bin/console app:flywheel:engine";

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            @pclose(@popen("start /B " . $command, "r"));
        } else {
            @shell_exec($command . " > /dev/null 2>&1 &");
        }
    }

    /**
     * Registers subscribed kernel events.
     *
     * @return array Map of event names to handler methods.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 50],
        ];
    }
}
