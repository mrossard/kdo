<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Activity;
use App\Entity\GiftProject;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Activity>
 */
class ActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Activity::class);
    }

    /**
     * @return list<Activity>
     */
    public function findRecentForProject(GiftProject $project, int $limit = 20): array
    {
        /** @var list<Activity> */
        return $this
            ->createQueryBuilder('a')
            ->where('a.project = :project')
            ->setParameter('project', $project)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns unread activity count per project for a user.
     * A project has unread activities if it has activities newer than the user's lastReadAt.
     *
     * @return array<int, int> project id => count of unread activities
     */
    public function countUnreadPerProjectForUser(User $user): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT a.project_id, COUNT(a.id) as unread_count
            FROM activity a
            LEFT JOIN project_read_status prs
                ON prs.project_id = a.project_id AND prs.user_id = :userId
            INNER JOIN gift_project gp ON gp.id = a.project_id
            LEFT JOIN gift_project_user gpu ON gpu.gift_project_id = gp.id
            WHERE (gp.owner_id = :userId OR gpu.user_id = :userId)
              AND (gp.recipient_id IS NULL OR gp.recipient_id != :userId)
              AND (prs.last_read_at IS NULL OR a.created_at > prs.last_read_at)
            GROUP BY a.project_id
        ';

        $result = $conn->executeQuery($sql, ['userId' => $user->getId()]);

        $counts = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $counts[(int) $row['project_id']] = (int) $row['unread_count'];
        }

        return $counts;
    }

    /**
     * Returns total unread count across all projects for a user.
     */
    public function countTotalUnreadForUser(User $user): int
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT COUNT(a.id) as total
            FROM activity a
            LEFT JOIN project_read_status prs
                ON prs.project_id = a.project_id AND prs.user_id = :userId
            INNER JOIN gift_project gp ON gp.id = a.project_id
            LEFT JOIN gift_project_user gpu ON gpu.gift_project_id = gp.id
            WHERE (gp.owner_id = :userId OR gpu.user_id = :userId)
              AND (gp.recipient_id IS NULL OR gp.recipient_id != :userId)
              AND (prs.last_read_at IS NULL OR a.created_at > prs.last_read_at)
        ';

        $result = $conn->executeQuery($sql, ['userId' => $user->getId()]);

        return (int) $result->fetchOne();
    }

    /**
     * Returns recent activities across all projects for a user (dashboard feed).
     *
     * @return list<Activity>
     */
    public function findRecentForUser(User $user, int $limit = 5): array
    {
        /** @var list<Activity> */
        return $this
            ->createQueryBuilder('a')
            ->innerJoin('a.project', 'gp')
            ->leftJoin('gp.participants', 'p')
            ->where('gp.owner = :user OR p = :user')
            ->andWhere('gp.recipient IS NULL OR gp.recipient != :user')
            ->setParameter('user', $user)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns projects with unread activities for the notification page.
     *
     * @return array<int, array{project: GiftProject, count: int, latestActivity: Activity}>
     */
    public function findUnreadProjectsForUser(User $user): array
    {
        $unreadCounts = $this->countUnreadPerProjectForUser($user);

        if ($unreadCounts === []) {
            return [];
        }

        /** @var list<Activity> $projects */
        $projects = $this
            ->createQueryBuilder('a')
            ->innerJoin('a.project', 'gp')
            ->where('a.project IN (:projectIds)')
            ->setParameter('projectIds', array_keys($unreadCounts))
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($projects as $activity) {
            $project = $activity->getProject();
            $projectId = $project?->getId();
            if ($project === null || $projectId === null || array_key_exists($projectId, $result)) {
                continue;
            }

            $result[$projectId] = [
                'project' => $project,
                'count' => $unreadCounts[$projectId],
                'latestActivity' => $activity,
            ];
        }

        return $result;
    }
}
