<?php

declare(strict_types=1);

namespace App\Repository\Production;

use App\Entity\Production\BuildInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<BuildInstance> */
final class BuildInstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BuildInstance::class);
    }
}
