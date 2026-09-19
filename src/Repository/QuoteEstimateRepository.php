<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuoteEstimate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuoteEstimate>
 */
class QuoteEstimateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuoteEstimate::class);
    }

    /**
     * @param string|null $status Filter by status, or null to get all.
     * @param int $limit
     * @param int $offset
     * @return QuoteEstimate[]
     */
    public function findForAdminList(?string $status = null, int $limit = 20, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('q')
            ->orderBy('q.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($status !== null) {
            $qb->andWhere('q.status = :status')
                ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Count estimates, optionally filtered by status.
     */
    public function countAll(?string $status = null): int
    {
        $qb = $this->createQueryBuilder('q')
            ->select('COUNT(q.id)');

        if ($status !== null) {
            $qb->andWhere('q.status = :status')
                ->setParameter('status', $status);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
