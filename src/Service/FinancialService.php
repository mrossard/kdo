<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\GiftIdea;
use App\Entity\GiftProject;
use App\Entity\Reimbursement;
use App\Entity\User;
use App\Repository\GiftProjectRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;

// @mago-expect lint:cyclomatic-complexity,kan-defect
class FinancialService
{
    private GiftProjectRepository $giftProjectRepository;

    public function __construct(GiftProjectRepository $giftProjectRepository)
    {
        $this->giftProjectRepository = $giftProjectRepository;
    }

    public function settleDebts(
        User $debtor,
        User $creditor,
        EntityManagerInterface $entityManager,
        ?ActivityLogger $activityLogger = null,
    ): int {
        $count = 0;
        $projects = $this->giftProjectRepository->findProjectsInvolvingUsers($debtor, $creditor);

        foreach ($projects as $project) {
            $financials = $this->calculate($project);
            foreach ($financials['transfers'] as $transfer) {
                if (!($transfer['from'] === $debtor && $transfer['to'] === $creditor)) {
                    continue;
                }

                $formattedAmount = number_format($transfer['amount'], 2, '.', '');
                $reimbursement = new Reimbursement();
                $reimbursement->setProject($project);
                $reimbursement->setFromUser($debtor);
                $reimbursement->setToUser($creditor);
                $reimbursement->setAmount($formattedAmount);

                $entityManager->persist($reimbursement);
                $activityLogger?->log($project, Activity::TYPE_REIMBURSEMENT_CREATED, $creditor, [
                    'amount' => $formattedAmount,
                ]);
                $count++;
            }
        }
        $entityManager->flush();

        return $count;
    }

    /**
     * @return array{
     *     summary: list<array{user: User, paid: float, balance: float}>,
     *     transfers: list<array{from: User, to: User, amount: float}>,
     *     total_cost: float,
     *     average_cost: float,
     *     reimbursements: Collection<int, Reimbursement>
     * }
     */
    public function calculate(GiftProject $project): array
    {
        $participants = $this->getProjectParticipants($project);
        /** @var array<int, float> $paidBy */
        $paidBy = [];
        /** @var array<int, User> $userMap */
        $userMap = [];

        foreach ($participants as $p) {
            $pid = $p->getId();
            if ($pid === null) {
                continue;
            }
            $paidBy[$pid] = 0.0;
            $userMap[$pid] = $p;
        }

        $totalCost = $this->accumulatePaidIdeas($project, $paidBy);
        $count = count($participants);
        $averageCost = $count > 0 ? $totalCost / $count : 0.0;

        /** @var array<int, float> $balances */
        $balances = [];
        foreach ($paidBy as $uid => $amount) {
            $balances[$uid] = $amount - $averageCost;
        }

        $reimbursements = $project->getReimbursements();
        $this->applyReimbursements($reimbursements, $balances);

        /** @var list<array{user: User, paid: float, balance: float}> $summary */
        $summary = [];
        foreach ($paidBy as $uid => $amount) {
            $summary[] = [
                'user' => $userMap[$uid],
                'paid' => $amount,
                'balance' => $balances[$uid],
            ];
        }

        return [
            'summary' => $summary,
            'transfers' => $this->computeTransfers($balances, $userMap),
            'total_cost' => $totalCost,
            'average_cost' => $averageCost,
            'reimbursements' => $reimbursements,
        ];
    }

    public function getContactCumulativeBalance(User $currentUser, User $contact): float
    {
        $cumulativeBalance = 0.0;
        $projects = $this->giftProjectRepository->findProjectsInvolvingUsers($currentUser, $contact);

        foreach ($projects as $project) {
            $financials = $this->calculate($project);

            foreach ($financials['transfers'] as $transfer) {
                if (
                    $transfer['from']->getId() === $contact->getId()
                    && $transfer['to']->getId() === $currentUser->getId()
                ) {
                    $cumulativeBalance -= $transfer['amount'];
                    continue;
                }

                if (
                    $transfer['from']->getId() === $currentUser->getId()
                    && $transfer['to']->getId() === $contact->getId()
                ) {
                    $cumulativeBalance += $transfer['amount'];
                }
            }
        }

        return $cumulativeBalance;
    }

    /**
     * @return list<User>
     */
    private function getProjectParticipants(GiftProject $project): array
    {
        $participants = array_values($project->getParticipants()->toArray());
        $owner = $project->getOwner();
        if ($owner !== null && !$project->getParticipants()->contains($owner)) {
            $participants[] = $owner;
        }

        return $participants;
    }

    /**
     * @param array<int, float> $paidBy
     */
    private function accumulatePaidIdeas(GiftProject $project, array &$paidBy): float
    {
        $totalCost = 0.0;
        foreach ($project->getGiftIdeas() as $idea) {
            $purchaser = $idea->getPurchasedBy();
            $price = $idea->getPurchasePrice();
            if ($idea->getStatus() !== GiftIdea::STATUS_SELECTED || $purchaser === null || $price === null) {
                continue;
            }

            $uid = $purchaser->getId();
            if ($uid === null || !array_key_exists($uid, $paidBy)) {
                continue;
            }

            $amount = is_numeric($price) ? (float) $price : 0.0;
            $paidBy[$uid] += $amount;
            $totalCost += $amount;
        }

        return $totalCost;
    }

    /**
     * @param Collection<int, Reimbursement> $reimbursements
     * @param array<int, float> $balances
     */
    private function applyReimbursements(Collection $reimbursements, array &$balances): void
    {
        foreach ($reimbursements as $r) {
            $fid = $r->getFromUser()?->getId();
            $tid = $r->getToUser()?->getId();
            $rawAmount = $r->getAmount();
            $amount = $rawAmount !== null && is_numeric($rawAmount) ? (float) $rawAmount : 0.0;

            if ($fid !== null && array_key_exists($fid, $balances)) {
                $balances[$fid] += $amount;
            }
            if ($tid !== null && array_key_exists($tid, $balances)) {
                $balances[$tid] -= $amount;
            }
        }
    }

    /**
     * @param array<int, float> $balances
     * @param array<int, User> $userMap
     * @return list<array{from: User, to: User, amount: float}>
     */
    private function computeTransfers(array $balances, array $userMap): array
    {
        /** @var array<int, float> $debtors */
        $debtors = [];
        /** @var array<int, float> $creditors */
        $creditors = [];
        foreach ($balances as $uid => $balance) {
            if ($balance < -0.01) {
                $debtors[$uid] = $balance;
            }
            if ($balance > 0.01) {
                $creditors[$uid] = $balance;
            }
        }

        $transfers = [];
        while (count($debtors) > 0 && count($creditors) > 0) {
            $dUid = array_key_first($debtors);
            $cUid = array_key_first($creditors);

            $debt = abs($debtors[$dUid]);
            $credit = $creditors[$cUid];
            $amount = min($debt, $credit);

            $transfers[] = [
                'from' => $userMap[$dUid],
                'to' => $userMap[$cUid],
                'amount' => $amount,
            ];

            $debtors[$dUid] += $amount;
            $creditors[$cUid] -= $amount;

            if (abs($debtors[$dUid]) < 0.01) {
                unset($debtors[$dUid]);
            }
            if ($creditors[$cUid] < 0.01) {
                unset($creditors[$cUid]);
            }
        }

        return $transfers;
    }
}
