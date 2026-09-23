<?php

namespace App\Repository;

use App\Entity\PersistentCache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * PersistentCacheRepository
 *
 * Repository for querying, validating TTL expiration, and purging persistent cache entries.
 *
 * @extends ServiceEntityRepository<PersistentCache>
 */
class PersistentCacheRepository extends ServiceEntityRepository
{
    /**
     * Initializes the repository.
     *
     * @param ManagerRegistry $registry Doctrine manager registry.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersistentCache::class);
    }

    /**
     * Finds a valid, unexpired cache item by key.
     *
     * @param string $key Unique cache key.
     * @return PersistentCache|null Entity or null if expired/missing.
     */
    public function findValid(string $key): ?PersistentCache
    {
        $now = new \DateTimeImmutable();
        return $this->createQueryBuilder('c')
            ->andWhere('c.cacheKey = :key')
            ->andWhere('c.expiresAt > :now')
            ->setParameter('key', $key)
            ->setParameter('now', $now)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Deletes all expired cache records from SQLite.
     *
     * @return int Number of purged records.
     */
    public function purgeExpired(): int
    {
        $now = new \DateTimeImmutable();
        return $this->createQueryBuilder('c')
            ->delete()
            ->where('c.expiresAt <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
    }

    /**
     * Deletes all cache entries matching a key prefix.
     *
     * @param string $prefix Key prefix string.
     * @return int Number of purged records.
     */
    public function purgePrefix(string $prefix): int
    {
        return $this->createQueryBuilder('c')
            ->delete()
            ->where('c.cacheKey LIKE :prefix')
            ->setParameter('prefix', $prefix . '%')
            ->getQuery()
            ->execute();
    }

    /**
     * Flushes all records from the persistent cache table.
     *
     * @return int Number of purged records.
     */
    public function purgeAll(): int
    {
        return $this->createQueryBuilder('c')
            ->delete()
            ->getQuery()
            ->execute();
    }

    /**
     * Counts currently active (non-expired) cache records.
     *
     * @return int Total active cache entries.
     */
    public function countActive(): int
    {
        $now = new \DateTimeImmutable();
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.expiresAt > :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
