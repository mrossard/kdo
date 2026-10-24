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
    #[Route('/', name: 'app_home')]
    public function index(
        GiftProjectRepository $projectRepository,
        WishRepository $wishRepository,
        FinancialService $financialService,
        WishlistShareRepository $wishlistShareRepository,
        ContactRequestRepository $contactRequestRepository,
        ActivityRepository $activityRepository,
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
            // Calculate financials for all projects (including archived ones?)
            // Usually you want to know debts even if archived. Let's include all.
            $financials = $financialService->calculate($project);
            foreach ($financials['summary'] as $row) {
                if ($row['user'] !== $user) {
                    continue;
                }

                $globalBalance += $row['balance'];
            }
        }

        // Contacts: count mutual wishlist shares (both directions)
        $shares = $wishlistShareRepository
            ->createQueryBuilder('ws')
            ->where('ws.owner = :user OR ws.recipient = :user')
            ->andWhere('ws.owner != ws.recipient')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $mutualContactIds = [];
        foreach ($shares as $share) {
            $other = $share->getOwner() === $user ? $share->getRecipient() : $share->getOwner();
            if (!$other) {
                continue;
            }
            $reverse = $wishlistShareRepository->findOneBy([
                'owner' => $other,
                'recipient' => $user,
            ]);
            if ($reverse) {
                $mutualContactIds[$other->getId()] = true;
            }
        }
        $contactsCount = count($mutualContactIds);

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
            'recent_activities' => $activityRepository->findRecentForUser($user, 5),
        ]);
    }
}
