<?php

namespace App\Repository;

use App\Entity\AppConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * AppConfigRepository
 *
 * Repository for querying application configurations stored in SQLite.
 *
 * @extends ServiceEntityRepository<AppConfig>
 */
class AppConfigRepository extends ServiceEntityRepository
{
    /**
     * Initializes the repository.
     *
     * @param ManagerRegistry $registry Doctrine manager registry.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppConfig::class);
    }

    /**
     * Finds a configuration entity by its unique key name.
     *
     * @param string $key Unique configuration key.
     * @return AppConfig|null AppConfig entity or null if not found.
     */
    public function findByKey(string $key): ?AppConfig
    {
        return $this->findOneBy(['configKey' => $key]);
    }
}
