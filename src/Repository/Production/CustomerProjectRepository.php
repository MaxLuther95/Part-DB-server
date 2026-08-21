<?php

declare(strict_types=1);

namespace App\Repository\Production;

use App\Entity\Production\CustomerProject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CustomerProject> */
final class CustomerProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerProject::class);
    }
}
