<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\GiftIdeaRepository;
use App\Repository\GiftProjectRepository;
use App\Repository\ReimbursementRepository;
use App\Service\FinancialService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/financial')]
#[IsGranted('ROLE_USER')]
class FinancialController extends AbstractController
{
    #[Route('/', name: 'app_financial_index', methods: ['GET'])]
    public function index(
        GiftProjectRepository $projectRepository,
        FinancialService $financialService,
        ReimbursementRepository $reimbursementRepository,
        GiftIdeaRepository $giftIdeaRepository
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $projects = $projectRepository->findAccessibleProjectsForUser($user);

        $debts = [];
        $credits = [];

        foreach ($projects as $project) {
            $financials = $financialService->calculate($project);
            
            foreach ($financials['transfers'] as $transfer) {
                if ($transfer['from']->getId() === $user->getId()) {
                    $debts[] = [
                        'project' => $project,
                        'to' => $transfer['to'],
                        'amount' => $transfer['amount'],
                    ];
                } elseif ($transfer['to']->getId() === $user->getId()) {
                    $credits[] = [
                        'project' => $project,
                        'from' => $transfer['from'],
                        'amount' => $transfer['amount'],
                    ];
                }
            }
        }

        $history = $reimbursementRepository->findByUserInvolvement($user);
        $purchasedItems = $giftIdeaRepository->findPurchasedByUser($user);

        return $this->render('financial/index.html.twig', [
            'debts' => $debts,
            'credits' => $credits,
            'history' => $history,
            'purchasedItems' => $purchasedItems,
        ]);
    }
}
