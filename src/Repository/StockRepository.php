<?php

namespace App\Repository;

use App\Entity\Stock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * StockRepository
 *
 * Repository for querying and filtering tracked stocks and ETFs by sector, risk profile, asset type, or keyword search.
 *
 * @extends ServiceEntityRepository<Stock>
 */
class StockRepository extends ServiceEntityRepository
{
    /**
     * Initializes the stock repository.
     *
     * @param ManagerRegistry $registry Doctrine manager registry.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Stock::class);
    }

    /**
     * Finds stocks matching the given filter criteria ordered by conviction score descending.
     *
     * @param string|null $sector    Optional sector filter (or 'ALL').
     * @param string|null $risk      Optional risk rating filter (or 'ALL').
     * @param string|null $query     Optional keyword search query for symbol, name, or sector.
     * @param string|null $assetType Optional asset type filter ('STOCK', 'ETF', 'ALL').
     * @return Stock[] Array of matching Stock entities.
     */
    public function findByFilters(?string $sector = null, ?string $risk = null, ?string $query = null, ?string $assetType = null): array
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

        if ($assetType && $assetType !== 'ALL') {
            $qb->andWhere('s.assetType = :assetType')
               ->setParameter('assetType', $assetType);
        }

        if ($query && trim($query) !== '') {
            $qb->andWhere('s.symbol LIKE :q OR s.name LIKE :q OR s.sector LIKE :q')
               ->setParameter('q', '%' . trim($query) . '%');
        }

        $qb->orderBy('s.score', 'DESC');

        return $qb->getQuery()->getResult();
    }
}
