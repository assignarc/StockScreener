<?php

namespace App\Repository;

use App\Entity\Watchlist;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * WatchlistRepository
 *
 * Repository for managing and querying user watchlist symbols.
 *
 * @extends ServiceEntityRepository<Watchlist>
 */
class WatchlistRepository extends ServiceEntityRepository
{
    /**
     * Initializes the watchlist repository.
     *
     * @param ManagerRegistry $registry Doctrine manager registry.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Watchlist::class);
    }
}
