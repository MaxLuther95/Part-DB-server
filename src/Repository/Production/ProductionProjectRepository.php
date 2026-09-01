<?php

declare(strict_types=1);

namespace App\Repository\Production;

use App\Entity\Production\ProductionProject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ProductionProject> */
final class ProductionProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductionProject::class);
    }

    /** @return list<ProductionProject> */
    public function findAllWithOrders(): array
    {
        return $this->createQueryBuilder('project')
            ->leftJoin('project.orders', 'orders')
            ->addSelect('orders')
            ->orderBy('project.projectNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
