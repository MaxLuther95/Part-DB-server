<?php

declare(strict_types=1);

namespace App\Repository\Production;

use App\Entity\Production\BuildMaterialUsage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<BuildMaterialUsage> */
final class BuildMaterialUsageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BuildMaterialUsage::class);
    }
}
