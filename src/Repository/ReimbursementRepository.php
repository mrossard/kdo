<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Reimbursement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reimbursement>
 */
class ReimbursementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reimbursement::class);
    }

    /**
     * @return list<Reimbursement>
     */
    public function findByUserInvolvement(\App\Entity\User $user): array
    {
        /** @var list<Reimbursement> */
        return $this
            ->createQueryBuilder('r')
            ->leftJoin('r.project', 'p')
            ->addSelect('p')
            ->leftJoin('r.fromUser', 'fu')
            ->addSelect('fu')
            ->leftJoin('r.toUser', 'tu')
            ->addSelect('tu')
            ->where('r.fromUser = :user OR r.toUser = :user')
            ->setParameter('user', $user)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
