<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * Kernel
 *
 * Core Symfony application kernel handling bundle registration, routing,
 * dependency injection container compilation, and environment initialization.
 */
class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Returns an array of allowed environment identifiers for APP_ENV.
     *
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
