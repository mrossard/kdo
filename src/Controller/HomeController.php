<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\ContactRequestRepository;
use App\Repository\GiftProjectRepository;
use App\Repository\WishlistShareRepository;
use App\Repository\WishRepository;
use App\Service\FinancialService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(
        private readonly ActivityRepository $activityRepository,
    ) {}

    #[Route('/', name: 'app_home')]
    public function index(
        GiftProjectRepository $projectRepository,
        WishRepository $wishRepository,
        FinancialService $financialService,
        WishlistShareRepository $wishlistShareRepository,
        ContactRequestRepository $contactRequestRepository,
    ): Response {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->render('home/index.html.twig');
        }

        $wishCount = $wishRepository->count(['user' => $user]);

        $allProjects = $projectRepository->findAccessibleProjectsForUser($user);
        $activeProjects = array_filter($allProjects, static fn($p) => !$p->isArchived());
        $activeProjectsCount = count($activeProjects);

        $globalBalance = 0.0;
        foreach ($allProjects as $project) {
            $financials = $financialService->calculate($project);
            foreach ($financials['summary'] as $row) {
                if ($row['user'] !== $user) {
                    continue;
                }

                $globalBalance += $row['balance'];
            }
        }

        $contactsCount = $wishlistShareRepository->countMutualContactsForUser($user);

        // Pending contact requests incoming for the user
        $pendingContactsCount = $contactRequestRepository->count([
            'recipient' => $user,
            'status' => \App\Entity\ContactRequest::STATUS_PENDING,
        ]);

        return $this->render('home/index.html.twig', [
            'wish_count' => $wishCount,
            'active_projects_count' => $activeProjectsCount,
            'global_balance' => $globalBalance,
            'contacts_count' => $contactsCount,
            'pending_contacts_count' => $pendingContactsCount,
            'recent_activities' => $this->activityRepository->findRecentForUser($user, 5),
        ]);
    }
}
