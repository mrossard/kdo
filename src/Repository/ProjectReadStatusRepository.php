<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GiftProject;
use App\Entity\ProjectReadStatus;
use App\Entity\User;
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

    public function markAsRead(User $user, GiftProject $project, ?\DateTimeImmutable $readAt = null): void
    {
        $this->markManyAsRead($user, [$project], $readAt);
    }

    /**
     * @param list<GiftProject> $projects
     */
    public function markManyAsRead(User $user, array $projects, ?\DateTimeImmutable $readAt = null): void
    {
        if ($projects === []) {
            return;
        }

        $em = $this->getEntityManager();
        $existingStatuses = $this->findBy(['user' => $user, 'project' => $projects]);
        $statusByProjectId = [];

        foreach ($existingStatuses as $status) {
            $pid = $status->getProject()?->getId();
            if ($pid !== null) {
                $statusByProjectId[$pid] = $status;
            }
        }

        $now = $readAt ?? new \DateTimeImmutable();
        foreach ($projects as $project) {
            $pid = $project->getId();
            $readStatus = $pid !== null ? $statusByProjectId[$pid] ?? null : null;

            if ($readStatus === null) {
                $readStatus = new ProjectReadStatus();
                $readStatus->setUser($user);
                $readStatus->setProject($project);
                $em->persist($readStatus);
            }

            $readStatus->setLastReadAt($now);
        }
    }
}
