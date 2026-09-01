<?php

declare(strict_types=1);

namespace App\Repository\Production;

use App\Entity\Production\CustomerProject;
use App\Entity\Production\CustomerProjectStatus;
use App\Entity\UserSystem\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CustomerProject> */
final class CustomerProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerProject::class);
    }

    /** @return list<CustomerProject> */
    public function findAllWithAssignedUsers(?CustomerProjectStatus $status = null): array
    {
        $queryBuilder = $this->createQueryBuilder('project')
            ->leftJoin('project.assignedUsers', 'assigned_user')
            ->addSelect('assigned_user')
            ->orderBy('project.projectNumber', 'ASC');

        if (null !== $status) {
            $queryBuilder
                ->andWhere('project.status = :status')
                ->setParameter('status', $status);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /** @return list<CustomerProject> */
    public function findAssignedTo(User $user, ?CustomerProjectStatus $status = null): array
    {
        $queryBuilder = $this->createQueryBuilder('project')
            ->innerJoin('project.assignedUsers', 'membership', 'WITH', 'membership = :user')
            ->leftJoin('project.assignedUsers', 'assigned_user')
            ->addSelect('assigned_user')
            ->setParameter('user', $user)
            ->orderBy('project.projectNumber', 'ASC');

        if (null !== $status) {
            $queryBuilder
                ->andWhere('project.status = :status')
                ->setParameter('status', $status);
        }

        return $queryBuilder->getQuery()->getResult();
    }

}
