<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\ProjectReadStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/notifications')]
#[IsGranted('ROLE_USER')]
final class NotificationController extends AbstractHtmxController
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
        ProjectReadStatusRepository $readStatusRepository,
        Request $request,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->isCsrfTokenValid('mark_all_read', $request->getPayload()->getString('_token'))) {
            $unreadProjects = $activityRepository->findUnreadProjectsForUser($user);
            $projects = array_values(array_map(static fn(array $data) => $data['project'], $unreadProjects));

            if ($projects !== []) {
                $readStatusRepository->markManyAsRead($user, $projects);
                $entityManager->flush();
            }
        }

        return $this->redirectOrHtmx($request, 'app_notification_index');
    }
}
