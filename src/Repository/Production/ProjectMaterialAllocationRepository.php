<?php

declare(strict_types=1);

namespace App\Repository\Production;

use App\Entity\Production\ProjectMaterialAllocation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ProjectMaterialAllocation> */
final class ProjectMaterialAllocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectMaterialAllocation::class);
    }
}
