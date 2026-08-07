<?php

namespace App\Repository;

use App\Entity\Stock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Stock>
 */
class StockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Stock::class);
    }

    /**
     * @return Stock[]
     */
    public function findByFilters(?string $sector = null, ?string $risk = null, ?string $query = null): array
    {
        $qb = $this->createQueryBuilder('s');

        if ($sector && $sector !== 'ALL') {
            $qb->andWhere('s.sector = :sector')
               ->setParameter('sector', $sector);
        }

        if ($risk && $risk !== 'ALL') {
            $qb->andWhere('s.risk = :risk')
               ->setParameter('risk', $risk);
        }

        if ($query && trim($query) !== '') {
            $qb->andWhere('s.symbol LIKE :q OR s.name LIKE :q OR s.sector LIKE :q')
               ->setParameter('q', '%' . trim($query) . '%');
        }

        $qb->orderBy('s.score', 'DESC');

        return $qb->getQuery()->getResult();
    }
}
