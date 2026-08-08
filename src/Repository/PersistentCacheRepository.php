<?php

namespace App\Repository;

use App\Entity\PersistentCache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PersistentCache>
 */
class PersistentCacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersistentCache::class);
    }

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

    public function purgePrefix(string $prefix): int
    {
        return $this->createQueryBuilder('c')
            ->delete()
            ->where('c.cacheKey LIKE :prefix')
            ->setParameter('prefix', $prefix . '%')
            ->getQuery()
            ->execute();
    }

    public function purgeAll(): int
    {
        return $this->createQueryBuilder('c')
            ->delete()
            ->getQuery()
            ->execute();
    }

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
