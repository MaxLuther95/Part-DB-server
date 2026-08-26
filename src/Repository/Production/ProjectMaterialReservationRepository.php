<?php

declare(strict_types=1);

namespace App\Repository\Production;

use App\Entity\Parts\Part;
use App\Entity\Parts\PartLot;
use App\Entity\Production\CustomerProject;
use App\Entity\Production\ProjectMaterialReservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ProjectMaterialReservation> */
final class ProjectMaterialReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectMaterialReservation::class);
    }

    public function quantityForPart(Part $part, ?CustomerProject $excludeProject = null): int
    {
        $qb = $this->createQueryBuilder('reservation')
            ->select('COALESCE(SUM(reservation.quantity), 0)')
            ->where('reservation.part = :part')
            ->setParameter('part', $part);
        if (null !== $excludeProject) {
            $qb->andWhere('reservation.customerProject != :excluded')->setParameter('excluded', $excludeProject);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function quantityForLot(PartLot $lot, ?CustomerProject $excludeProject = null): int
    {
        $qb = $this->createQueryBuilder('reservation')
            ->select('COALESCE(SUM(reservation.quantity), 0)')
            ->where('reservation.sourcePartLot = :lot')
            ->setParameter('lot', $lot);
        if (null !== $excludeProject) {
            $qb->andWhere('reservation.customerProject != :excluded')->setParameter('excluded', $excludeProject);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
