<?php

declare(strict_types=1);

namespace App\Repository\Production;

use App\Entity\Production\ProjectAccessory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ProjectAccessory> */
final class ProjectAccessoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectAccessory::class);
    }
}
