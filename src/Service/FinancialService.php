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
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

// @mago-expect lint:cyclomatic-complexity,kan-defect,halstead
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
        return $entityManager->wrapInTransaction(function () use (
            $debtor,
            $creditor,
            $entityManager,
            $activityLogger,
        ): int {
            $count = 0;
            $projects = $this->giftProjectRepository->findProjectsInvolvingUsers($debtor, $creditor);

            foreach ($projects as $project) {
                if ($project->getId() !== null) {
                    $entityManager->lock($project, LockMode::PESSIMISTIC_WRITE);
                }

                $financials = $this->calculate($project);
                foreach ($financials['transfers'] as $transfer) {
                    if (
                        !(
                            $transfer['from']->getId() === $debtor->getId()
                            && $transfer['to']->getId() === $creditor->getId()
                        )
                    ) {
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
        });
    }

    /**
     * @return array{
     *     summary: list<array{user: User, paid: float, share: float, target: float, balance: float}>,
     *     transfers: list<array{from: User, to: User, amount: float}>,
     *     total_cost: float,
     *     average_cost: float,
     *     total_shares: float,
     *     cost_per_share: float,
     *     reimbursements: Collection<int, Reimbursement>
     * }
     */
    public function calculate(GiftProject $project): array
    {
        $participants = $this->getProjectParticipants($project);
        /** @var array<int, int> $paidByCents */
        $paidByCents = [];
        /** @var array<int, float> $sharesBy */
        $sharesBy = [];
        /** @var array<int, User> $userMap */
        $userMap = [];
        /** @var array<int, int> $directTargetsCents */
        $directTargetsCents = [];
        $totalShares = 0.0;

        foreach ($participants as $p) {
            $pid = $p->getId();
            if ($pid === null) {
                continue;
            }
            $paidByCents[$pid] = 0;
            $directTargetsCents[$pid] = 0;
            $share = $project->getParticipantShare($p);
            $sharesBy[$pid] = $share;
            $totalShares += $share;
            $userMap[$pid] = $p;
        }

        if ($totalShares <= 0.0 && count($sharesBy) > 0) {
            foreach (array_keys($sharesBy) as $pid) {
                $sharesBy[$pid] = 1.0;
            }
            $totalShares = (float) count($sharesBy);
        }

        $costs = $this->accumulatePaidIdeas($project, $paidByCents, $directTargetsCents);
        $totalCostCents = $costs['total'];
        $sharedPoolCostCents = $costs['shared'];
        $count = count($participants);
        $totalCost = $this->fromCents($totalCostCents);
        $averageCost = $count > 0 ? $totalCost / $count : 0.0;
        $costPerShare = $totalShares > 0.0 ? $totalCost / $totalShares : 0.0;

        /** @var array<int, int> $targetsCents */
        $targetsCents = [];
        /** @var array<int, int> $balancesCents */
        $balancesCents = [];
        foreach ($paidByCents as $uid => $amountCents) {
            $sharedTargetCents = $totalShares > 0.0
                ? (int) round(($sharedPoolCostCents * $sharesBy[$uid]) / $totalShares)
                : 0;
            $targetCents = $directTargetsCents[$uid] + $sharedTargetCents;
            $targetsCents[$uid] = $targetCents;
            $balancesCents[$uid] = $amountCents - $targetCents;
        }

        $reimbursements = $project->getReimbursements();
        $this->applyReimbursements($reimbursements, $balancesCents);

        /** @var list<array{user: User, paid: float, share: float, target: float, balance: float}> $summary */
        $summary = [];
        foreach ($paidByCents as $uid => $amountCents) {
            $summary[] = [
                'user' => $userMap[$uid],
                'paid' => $this->fromCents($amountCents),
                'share' => $sharesBy[$uid],
                'target' => $this->fromCents($targetsCents[$uid]),
                'balance' => $this->fromCents($balancesCents[$uid]),
            ];
        }

        return [
            'summary' => $summary,
            'transfers' => $this->computeTransfers($balancesCents, $userMap),
            'total_cost' => $totalCost,
            'average_cost' => $averageCost,
            'total_shares' => $totalShares,
            'cost_per_share' => $costPerShare,
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
     * @param array<int, int> $paidByCents
     * @param array<int, int> $directTargetsCents
     * @return array{total: int, shared: int}
     */
    private function accumulatePaidIdeas(GiftProject $project, array &$paidByCents, array &$directTargetsCents): array
    {
        $totalCostCents = 0;
        $sharedCostCents = 0;

        foreach ($project->getGiftIdeas() as $idea) {
            $purchaser = $idea->getPurchasedBy();
            $price = $idea->getPurchasePrice();
            if ($idea->getStatus() !== GiftIdea::STATUS_SELECTED || $purchaser === null || $price === null) {
                continue;
            }

            $uid = $purchaser->getId();
            if ($uid === null || !array_key_exists($uid, $paidByCents)) {
                continue;
            }

            $amountCents = $this->toCents($price);
            $paidByCents[$uid] += $amountCents;
            $totalCostCents += $amountCents;

            $totalContributedCents = $idea->isCrowdfunded() ? $this->toCents($idea->getTotalContributed()) : 0;
            if ($totalContributedCents > 0) {
                $scale = min(1.0, $amountCents / $totalContributedCents);
                $coveredCents = 0;
                foreach ($idea->getContributions() as $contrib) {
                    $cUser = $contrib->getUser();
                    $cUid = $cUser?->getId();
                    if ($cUid !== null && array_key_exists($cUid, $directTargetsCents)) {
                        $contribCents = (int) round($this->toCents($contrib->getAmount()) * $scale);
                        $directTargetsCents[$cUid] += $contribCents;
                        $coveredCents += $contribCents;
                    }
                }
                $sharedCostCents += max(0, $amountCents - $coveredCents);
                continue;
            }

            $sharedCostCents += $amountCents;
        }

        return ['total' => $totalCostCents, 'shared' => $sharedCostCents];
    }

    /**
     * @param Collection<int, Reimbursement> $reimbursements
     * @param array<int, int> $balancesCents
     */
    private function applyReimbursements(Collection $reimbursements, array &$balancesCents): void
    {
        foreach ($reimbursements as $r) {
            $fid = $r->getFromUser()?->getId();
            $tid = $r->getToUser()?->getId();
            $amountCents = $this->toCents($r->getAmount());

            if ($fid !== null && array_key_exists($fid, $balancesCents)) {
                $balancesCents[$fid] += $amountCents;
            }
            if ($tid !== null && array_key_exists($tid, $balancesCents)) {
                $balancesCents[$tid] -= $amountCents;
            }
        }
    }

    /**
     * @param array<int, int> $balancesCents
     * @param array<int, User> $userMap
     * @return list<array{from: User, to: User, amount: float}>
     */
    private function computeTransfers(array $balancesCents, array $userMap): array
    {
        /** @var array<int, int> $debtors */
        $debtors = [];
        /** @var array<int, int> $creditors */
        $creditors = [];
        foreach ($balancesCents as $uid => $balanceCents) {
            if ($balanceCents < 0) {
                $debtors[$uid] = $balanceCents;
            }
            if ($balanceCents > 0) {
                $creditors[$uid] = $balanceCents;
            }
        }

        $transfers = [];
        while (count($debtors) > 0 && count($creditors) > 0) {
            $dUid = array_key_first($debtors);
            $cUid = array_key_first($creditors);

            $debt = abs($debtors[$dUid]);
            $credit = $creditors[$cUid];
            $amountCents = min($debt, $credit);

            $transfers[] = [
                'from' => $userMap[$dUid],
                'to' => $userMap[$cUid],
                'amount' => $this->fromCents($amountCents),
            ];

            $debtors[$dUid] += $amountCents;
            $creditors[$cUid] -= $amountCents;

            if ($debtors[$dUid] >= 0) {
                unset($debtors[$dUid]);
            }
            if ($creditors[$cUid] <= 0) {
                unset($creditors[$cUid]);
            }
        }

        return $transfers;
    }

    private function toCents(string|float|null $amount): int
    {
        if ($amount === null || is_string($amount) && !is_numeric($amount)) {
            return 0;
        }

        return (int) round((float) $amount * 100);
    }

    private function fromCents(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
