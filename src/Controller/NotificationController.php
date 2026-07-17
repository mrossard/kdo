<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ProjectReadStatus;
use App\Entity\User;
use App\Repository\ActivityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/notifications')]
#[IsGranted('ROLE_USER')]
class NotificationController extends AbstractController
{
    #[Route('/', name: 'app_notification_index', methods: ['GET'])]
    public function index(ActivityRepository $activityRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $unreadProjects = $activityRepository->findUnreadProjectsForUser($user);
        $totalUnread = $activityRepository->countTotalUnreadForUser($user);

        return $this->render('notification/index.html.twig', [
            'unread_projects' => $unreadProjects,
            'total_unread' => $totalUnread,
        ]);
    }

    #[Route('/mark-all-read', name: 'app_notification_mark_all_read', methods: ['POST'])]
    public function markAllRead(
        EntityManagerInterface $entityManager,
        ActivityRepository $activityRepository,
        Request $request,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $unreadProjects = $activityRepository->findUnreadProjectsForUser($user);
        $now = new \DateTimeImmutable();

        foreach ($unreadProjects as $data) {
            $project = $data['project'];
            $readStatus = $entityManager
                ->getRepository(ProjectReadStatus::class)
                ->findOneBy(['user' => $user, 'project' => $project]);

            if (!$readStatus) {
                $readStatus = new ProjectReadStatus();
                $readStatus->setUser($user);
                $readStatus->setProject($project);
                $entityManager->persist($readStatus);
            }
            $readStatus->setLastReadAt($now);
        }

        $entityManager->flush();

        if ($request->headers->get('HX-Request')) {
            return new Response(null, 204, [
                'HX-Redirect' => $this->generateUrl('app_notification_index'),
            ]);
        }

        return $this->redirectToRoute('app_notification_index');
    }
}
