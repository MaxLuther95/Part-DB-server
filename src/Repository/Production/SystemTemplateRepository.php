<?php

declare(strict_types=1);

namespace App\Repository\Production;

use App\Entity\Production\SystemTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SystemTemplate> */
final class SystemTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SystemTemplate::class);
    }
}
