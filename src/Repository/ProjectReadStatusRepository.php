<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProjectReadStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectReadStatus>
 */
class ProjectReadStatusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectReadStatus::class);
    }
}
