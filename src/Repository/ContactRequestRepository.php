<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContactRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ContactRequest>
 */
class ContactRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactRequest::class);
    }

    public function findPendingInvitationByTokenAndEmail(
        #[\SensitiveParameter]
        Uuid $token,
        string $email,
    ): ?ContactRequest {
        /** @var ContactRequest|null */
        return $this
            ->createQueryBuilder('cr')
            ->where('cr.invitationToken = :token')
            ->andWhere('LOWER(cr.recipientEmail) = :email')
            ->andWhere('cr.status = :status')
            ->andWhere('cr.recipient IS NULL')
            ->setParameter('token', $token)
            ->setParameter('email', mb_strtolower(trim($email)))
            ->setParameter('status', ContactRequest::STATUS_PENDING)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countPendingInvitationsByEmail(string $email): int
    {
        return (int) $this
            ->createQueryBuilder('cr')
            ->select('COUNT(cr.id)')
            ->where('LOWER(cr.recipientEmail) = :email')
            ->andWhere('cr.status = :status')
            ->andWhere('cr.recipient IS NULL')
            ->setParameter('email', mb_strtolower(trim($email)))
            ->setParameter('status', ContactRequest::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<ContactRequest>
     */
    public function findPendingInvitationsByEmail(string $email): array
    {
        /** @var list<ContactRequest> */
        return $this
            ->createQueryBuilder('cr')
            ->where('LOWER(cr.recipientEmail) = :email')
            ->andWhere('cr.status = :status')
            ->andWhere('cr.recipient IS NULL')
            ->setParameter('email', mb_strtolower(trim($email)))
            ->setParameter('status', ContactRequest::STATUS_PENDING)
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return ContactRequest[] Returns an array of ContactRequest objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('c.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }
    //    public function findOneBySomeField($value): ?ContactRequest
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
