<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\GiftIdea;
use App\Entity\GiftProject;
use App\Entity\Reimbursement;
use App\Entity\User;
use App\Repository\GiftProjectRepository;
use App\Service\FinancialService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class FinancialServiceTest extends TestCase
{
    private function createUserWithId(int $id, string $name): User
    {
        $user = new User();
        $user->setEmail(strtolower($name) . '@example.com');
        $user->setFirstname($name);

        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);

        return $user;
    }

    public function testCalculateWithNoPurchases(): void
    {
        $repo = $this->createStub(GiftProjectRepository::class);
        $service = new FinancialService($repo);

        $owner = $this->createUserWithId(1, 'Alice');
        $bob = $this->createUserWithId(2, 'Bob');

        $project = new GiftProject();
        $project->setOwner($owner);
        $project->addParticipant($bob);

        // Proposed idea (not selected or purchased) should be ignored
        $idea = new GiftIdea();
        $idea->setName('Livre')->setPrice('30.00')->setStatus(GiftIdea::STATUS_PROPOSED);
        $project->addGiftIdea($idea);

        $result = $service->calculate($project);

        self::assertSame(0.0, $result['total_cost']);
        self::assertSame(0.0, $result['average_cost']);
        self::assertSame([], $result['transfers']);
        self::assertCount(2, $result['summary']);
    }

    public function testCalculateBalancesAndTransfers(): void
    {
        $repo = $this->createStub(GiftProjectRepository::class);
        $service = new FinancialService($repo);

        $alice = $this->createUserWithId(1, 'Alice');
        $bob = $this->createUserWithId(2, 'Bob');
        $charlie = $this->createUserWithId(3, 'Charlie');

        $project = new GiftProject();
        $project->setOwner($alice);
        $project->addParticipant($bob);
        $project->addParticipant($charlie);

        // Alice buys a selected gift for 90 € (3 participants => 30 € each)
        $idea1 = new GiftIdea();
        $idea1
            ->setName('Montre')
            ->setStatus(GiftIdea::STATUS_SELECTED)
            ->setPurchasedBy($alice)
            ->setPurchasePrice('90.00');
        $project->addGiftIdea($idea1);

        // Discarded idea even with purchasePrice should not be counted
        $idea2 = new GiftIdea();
        $idea2
            ->setName('Gadget')
            ->setStatus(GiftIdea::STATUS_DISCARDED)
            ->setPurchasedBy($bob)
            ->setPurchasePrice('60.00');
        $project->addGiftIdea($idea2);

        $result = $service->calculate($project);

        self::assertSame(90.0, $result['total_cost']);
        self::assertSame(30.0, $result['average_cost']);
        self::assertCount(2, $result['transfers']);

        // Bob and Charlie each owe 30 € to Alice
        foreach ($result['transfers'] as $transfer) {
            self::assertSame($alice, $transfer['to']);
            self::assertSame(30.0, $transfer['amount']);
            self::assertContains($transfer['from'], [$bob, $charlie]);
        }
    }

    public function testCalculateAccountsForReimbursements(): void
    {
        $repo = $this->createStub(GiftProjectRepository::class);
        $service = new FinancialService($repo);

        $alice = $this->createUserWithId(1, 'Alice');
        $bob = $this->createUserWithId(2, 'Bob');

        $project = new GiftProject();
        $project->setOwner($alice);
        $project->addParticipant($bob);

        $idea = new GiftIdea();
        $idea
            ->setName('Parfum')
            ->setStatus(GiftIdea::STATUS_SELECTED)
            ->setPurchasedBy($alice)
            ->setPurchasePrice('50.00');
        $project->addGiftIdea($idea);

        // Bob already reimbursed 50/2 = 25 € to Alice
        $reimbursement = new Reimbursement();
        $reimbursement
            ->setFromUser($bob)
            ->setToUser($alice)
            ->setAmount('25.00');
        $project->addReimbursement($reimbursement);

        $result = $service->calculate($project);

        self::assertSame(50.0, $result['total_cost']);
        self::assertSame(25.0, $result['average_cost']);
        self::assertSame([], $result['transfers']);

        foreach ($result['summary'] as $row) {
            self::assertEqualsWithDelta(0.0, $row['balance'], 0.001);
        }
    }

    public function testSettleDebtsCreatesReimbursementsAndFlushes(): void
    {
        $alice = $this->createUserWithId(1, 'Alice');
        $bob = $this->createUserWithId(2, 'Bob');

        $project = new GiftProject();
        $project->setOwner($alice);
        $project->addParticipant($bob);

        $idea = new GiftIdea();
        $idea
            ->setName('Jeu')
            ->setStatus(GiftIdea::STATUS_SELECTED)
            ->setPurchasedBy($alice)
            ->setPurchasePrice('40.00');
        $project->addGiftIdea($idea);

        $repo = $this->createMock(GiftProjectRepository::class);
        $repo
            ->expects(self::once())
            ->method('findProjectsInvolvingUsers')
            ->with($bob, $alice)
            ->willReturn([$project]);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (object $entity) use (&$persisted): bool {
                $persisted[] = $entity;
                return $entity instanceof Reimbursement;
            }));
        $em->expects(self::once())->method('flush');

        $service = new FinancialService($repo);
        $count = $service->settleDebts($bob, $alice, $em);

        self::assertSame(1, $count);
        self::assertCount(1, $persisted);
        /** @var Reimbursement $reimbursement */
        $reimbursement = $persisted[0];
        self::assertSame($project, $reimbursement->getProject());
        self::assertSame($bob, $reimbursement->getFromUser());
        self::assertSame($alice, $reimbursement->getToUser());
        self::assertSame('20.00', $reimbursement->getAmount());
    }

    public function testGetContactCumulativeBalance(): void
    {
        $alice = $this->createUserWithId(1, 'Alice');
        $bob = $this->createUserWithId(2, 'Bob');

        $project = new GiftProject();
        $project->setOwner($alice);
        $project->addParticipant($bob);

        $idea = new GiftIdea();
        $idea
            ->setName('Jeu')
            ->setStatus(GiftIdea::STATUS_SELECTED)
            ->setPurchasedBy($alice)
            ->setPurchasePrice('40.00');
        $project->addGiftIdea($idea);

        $repo = $this->createMock(GiftProjectRepository::class);
        $repo
            ->expects(self::once())
            ->method('findProjectsInvolvingUsers')
            ->with($alice, $bob)
            ->willReturn([$project]);

        $service = new FinancialService($repo);
        $balance = $service->getContactCumulativeBalance($alice, $bob);

        // Bob paid 0, average is 20 -> Bob owes 20.0 to Alice (-20.0)
        self::assertEqualsWithDelta(-20.0, $balance, 0.001);
    }

    public function testGetContactCumulativeBalanceWithThreeParticipantsOnlyCountsDirectTransfers(): void
    {
        $alice = $this->createUserWithId(1, 'Alice');
        $bob = $this->createUserWithId(2, 'Bob');
        $charlie = $this->createUserWithId(3, 'Charlie');

        $project = new GiftProject();
        $project->setOwner($bob);
        $project->addParticipant($alice);
        $project->addParticipant($charlie);

        // Bob paid 90 € -> Alice owes 30 € to Bob, Charlie owes 30 € to Bob, Charlie owes 0 € to Alice
        $idea = new GiftIdea();
        $idea
            ->setName('Cadeau Commun')
            ->setStatus(GiftIdea::STATUS_SELECTED)
            ->setPurchasedBy($bob)
            ->setPurchasePrice('90.00');
        $project->addGiftIdea($idea);

        $repo = $this->createMock(GiftProjectRepository::class);
        $repo
            ->expects(self::exactly(2))
            ->method('findProjectsInvolvingUsers')
            ->willReturn([$project]);

        $service = new FinancialService($repo);

        // Charlie owes Bob, not Alice -> balance between Alice and Charlie is 0.0
        self::assertEqualsWithDelta(0.0, $service->getContactCumulativeBalance($alice, $charlie), 0.001);
        // Alice owes Bob 30 € -> balance from Alice's perspective with Bob is +30.0
        self::assertEqualsWithDelta(30.0, $service->getContactCumulativeBalance($alice, $bob), 0.001);
    }

    public function testCalculateWithCustomParticipantShares(): void
    {
        $repo = $this->createStub(GiftProjectRepository::class);
        $service = new FinancialService($repo);

        $alice = $this->createUserWithId(1, 'Alice');
        $bob = $this->createUserWithId(2, 'Bob');

        $project = new GiftProject();
        $project->setOwner($alice);
        $project->addParticipant($bob);

        // Alice participates as a couple (2 shares), Bob alone (1 share) -> 3 shares total
        $project->setParticipantShare($alice, 2.0);
        $project->setParticipantShare($bob, 1.0);

        // Alice pays 90 € -> cost per share = 30 € -> Alice's target is 60 €, Bob's target is 30 €
        $idea = new GiftIdea();
        $idea
            ->setName('Table')
            ->setStatus(GiftIdea::STATUS_SELECTED)
            ->setPurchasedBy($alice)
            ->setPurchasePrice('90.00');
        $project->addGiftIdea($idea);

        $result = $service->calculate($project);

        self::assertSame(90.0, $result['total_cost']);
        self::assertSame(3.0, $result['total_shares']);
        self::assertSame(30.0, $result['cost_per_share']);
        self::assertCount(1, $result['transfers']);
        self::assertSame($bob, $result['transfers'][0]['from']);
        self::assertSame($alice, $result['transfers'][0]['to']);
        self::assertEqualsWithDelta(30.0, $result['transfers'][0]['amount'], 0.001);
    }
}
