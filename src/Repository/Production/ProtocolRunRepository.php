<?php

declare(strict_types=1);

namespace App\Repository\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\ProtocolRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProtocolRun>
 */
final class ProtocolRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProtocolRun::class);
    }

    public function getNextRunNumber(BuildInstance $buildInstance): int
    {
        $highest = $this->createQueryBuilder('run')
            ->select('MAX(run.runNumber)')
            ->andWhere('run.buildInstance = :buildInstance')
            ->setParameter('buildInstance', $buildInstance)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $highest + 1;
    }
}
