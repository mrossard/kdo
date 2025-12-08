<?php

namespace App\Service;

use App\Entity\GiftIdea;
use App\Entity\GiftProject;
use App\Entity\Reimbursement;
use App\Entity\User;
use App\Repository\GiftProjectRepository;
use Doctrine\ORM\EntityManagerInterface;

class FinancialService
{
    private GiftProjectRepository $giftProjectRepository;

    public function __construct(GiftProjectRepository $giftProjectRepository)
    {
        $this->giftProjectRepository = $giftProjectRepository;
    }

    public function settleDebts(User $debtor, User $creditor, EntityManagerInterface $entityManager): int
    {
        $count = 0;
        $projects = $this->giftProjectRepository->findProjectsInvolvingUsers($debtor, $creditor);

        foreach ($projects as $project) {
            $financials = $this->calculate($project);
            foreach ($financials['transfers'] as $transfer) {
                if ($transfer['from'] === $debtor && $transfer['to'] === $creditor) {
                    // Create Reimbursement
                    $reimbursement = new Reimbursement();
                    $reimbursement->setProject($project);
                    $reimbursement->setFromUser($debtor);
                    $reimbursement->setToUser($creditor);
                    $reimbursement->setAmount((string)$transfer['amount']);

                    $entityManager->persist($reimbursement);
                    $count++;
                }
            }
        }
        $entityManager->flush();
        return $count;
    }

    public function calculate(GiftProject $project): array
    {
        $participants = $project->getParticipants()->toArray();
        if (!$project->getParticipants()->contains($project->getOwner())) {
            $participants[] = $project->getOwner();
        }

        $paidBy = []; // UserID => amount
        $userMap = []; // UserID => User object

        foreach ($participants as $p) {
            $paidBy[$p->getId()] = 0.0;
            $userMap[$p->getId()] = $p;
        }

        $totalCost = 0.0;
        foreach ($project->getGiftIdeas() as $idea) {
            if ($idea->getStatus() === GiftIdea::STATUS_SELECTED && $idea->getPurchasedBy() && $idea->getPurchasePrice()) {
                $uid = $idea->getPurchasedBy()->getId();
                $amount = (float) $idea->getPurchasePrice();
                
                // Only count if the purchaser is part of the group (should be, but safety check)
                if (isset($paidBy[$uid])) {
                    $paidBy[$uid] += $amount;
                    $totalCost += $amount;
                }
            }
        }

        $count = count($participants);
        $averageCost = $count > 0 ? $totalCost / $count : 0;

        $balances = [];
        $summary = [];
        foreach ($paidBy as $uid => $amount) {
            $balance = $amount - $averageCost;
            $balances[$uid] = $balance;
            $summary[] = [
                'user' => $userMap[$uid],
                'paid' => $amount,
                'balance' => $balance, // Initial balance before reimbursements
            ];
        }

        // Apply Reimbursements
        $reimbursements = $project->getReimbursements();
        foreach ($reimbursements as $r) {
            $fid = $r->getFromUser()->getId();
            $tid = $r->getToUser()->getId();
            $amount = (float) $r->getAmount();

            if (isset($balances[$fid])) {
                $balances[$fid] += $amount; // Paid debt
            }
            if (isset($balances[$tid])) {
                $balances[$tid] -= $amount; // Received payment
            }
        }

        // Update summary with final balance? Or keep "Theoretical" balance?
        // The prompt says: "indicate that he has been reimbursed".
        // The "Equilibrage" section shows remaining debts.
        // The "Summary" table shows "Paid" and "Balance".
        // If I adjust balance in Summary, it reflects "Net Position".
        // If A owed 50 and paid 50, A's Net Position is 0.
        // Users want to see if they are "squared".
        // So yes, update summary balances too.
        
        foreach ($summary as &$row) {
            $uid = $row['user']->getId();
            $row['balance'] = $balances[$uid];
        }
        unset($row); // Break reference

        // Calculate Transfers (Greedy Algorithm)
        $debtors = [];
        $creditors = [];
        foreach ($balances as $uid => $balance) {
            if ($balance < -0.01) $debtors[$uid] = $balance; // Negative balance = owes money
            if ($balance > 0.01) $creditors[$uid] = $balance; // Positive balance = needs reimbursement
        }

        $transfers = [];
        
        // While we have both debtors and creditors
        while (count($debtors) > 0 && count($creditors) > 0) {
            // Get first debtor and creditor
            $dUid = array_key_first($debtors);
            $cUid = array_key_first($creditors);
            
            $debt = abs($debtors[$dUid]);
            $credit = $creditors[$cUid];
            
            $amount = min($debt, $credit);
            
            $transfers[] = [
                'from' => $userMap[$dUid],
                'to' => $userMap[$cUid],
                'amount' => $amount
            ];
            
            // Update balances
            $debtors[$dUid] += $amount;
            $creditors[$cUid] -= $amount;
            
            // Remove settled accounts
            if (abs($debtors[$dUid]) < 0.01) unset($debtors[$dUid]);
            if ($creditors[$cUid] < 0.01) unset($creditors[$cUid]);
        }

        return [
            'summary' => $summary,
            'transfers' => $transfers,
            'total_cost' => $totalCost,
            'average_cost' => $averageCost,
            'reimbursements' => $reimbursements,
        ];
    }

    public function getContactCumulativeBalance(User $currentUser, User $contact): float
    {
        $cumulativeBalance = 0.0;

        // Find projects where currentUser and contact are involved
        $projects = $this->giftProjectRepository->findProjectsInvolvingUsers($currentUser, $contact);

        foreach ($projects as $project) {
            $financials = $this->calculate($project); // Use existing calculation logic

            foreach ($financials['summary'] as $participantSummary) {
                if ($participantSummary['user']->getId() === $contact->getId()) {
                    $cumulativeBalance += $participantSummary['balance'];
                    break; // Found contact's balance for this project, move to next project
                }
            }
        }

        return $cumulativeBalance;
    }
}
