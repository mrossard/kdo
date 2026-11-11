<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Activity;
use App\Entity\ContactRequest;
use App\Entity\GiftIdea;
use App\Entity\GiftProject;
use App\Entity\User;
use App\Entity\WishlistShare;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final class AppControllersTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connection = $this->em->getConnection();
        $connection->executeStatement(
            'TRUNCATE TABLE vote, comment, activity, project_read_status, reimbursement, gift_idea, gift_project_user, gift_project, wish, wishlist_share, contact_request, reset_password_request, "user" RESTART IDENTITY CASCADE',
        );
    }

    private function createUser(string $email, string $firstname, array $roles = ['ROLE_USER']): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setFirstname($firstname);
        $user->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);

        return $user;
    }

    public function testHomeFinancialNotificationThemeProfileAndAdminControllers(): void
    {
        $alice = $this->createUser('alice@example.com', 'Alice', ['ROLE_ADMIN']);
        $bob = $this->createUser('bob@example.com', 'Bob');
        $charlie = $this->createUser('charlie@example.com', 'Charlie');

        // Mutual shares
        $this->em->persist(new WishlistShare()->setOwner($alice)->setRecipient($bob));
        $this->em->persist(new WishlistShare()->setOwner($bob)->setRecipient($alice));

        // Project with purchase & activity
        $project = new GiftProject()
            ->setName('Cadeau Charlie')
            ->setOwner($alice)
            ->setRecipient($charlie)
            ->addParticipant($bob)
            ->setLocked(true);
        $this->em->persist($project);

        $idea = new GiftIdea()
            ->setName('Enceinte')
            ->setStatus(GiftIdea::STATUS_SELECTED)
            ->setGiftProject($project)
            ->setProposer($alice)
            ->setPurchasedBy($alice)
            ->setPurchasePrice('80.00');
        $this->em->persist($idea);

        $activity = new Activity()
            ->setProject($project)
            ->setUser($alice)
            ->setType(Activity::TYPE_IDEA_ADDED)
            ->setMeta(['idea_name' => 'Enceinte']);
        $this->em->persist($activity);
        $this->em->flush();

        // 1. Anonymous Home & Login page
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        // 2. Authenticated Home Dashboard (Bob has unread notification & debt)
        $this->client->loginUser($bob);
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        // 3. Financial index for Bob (debt) and Alice (credit)
        $this->client->request('GET', '/financial/');
        self::assertResponseIsSuccessful();

        $this->client->loginUser($alice);
        $this->client->request('GET', '/financial/');
        self::assertResponseIsSuccessful();

        // 4. Notifications index & mark-all-read for Bob
        $this->client->loginUser($bob);
        $notifCrawler = $this->client->request('GET', '/notifications/');
        self::assertResponseIsSuccessful();
        $markAllReadToken = $notifCrawler->filter('form[action$="/mark-all-read"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/notifications/mark-all-read', ['_token' => $markAllReadToken], [], ['HTTP_HX-Request' => 'true']);
        self::assertResponseStatusCodeSame(204);

        // 5. Theme toggle (light -> dark -> light)
        $this->client->request('POST', '/theme/toggle');
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/theme/toggle');
        self::assertResponseIsSuccessful();

        // 6. Profile update & password change
        $profileCrawler = $this->client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
        $profileForm = $profileCrawler->selectButton('Mettre à jour mes infos')->form([
            'user_profile[firstname]' => 'Robert',
            'user_profile[lastname]' => 'Martin',
        ]);
        $this->client->submit($profileForm);
        self::assertResponseRedirects('/profile');

        $profileCrawler = $this->client->request('GET', '/profile');
        $pwdForm = $profileCrawler->selectButton('Changer le mot de passe')->form([
            'change_password_form[currentPassword]' => 'secret123',
            'change_password_form[plainPassword][first]' => 'newsecret123',
            'change_password_form[plainPassword][second]' => 'newsecret123',
        ]);
        $this->client->submit($pwdForm);
        self::assertResponseRedirects('/profile');

        // 7. Admin users page (Alice has ROLE_ADMIN)
        $this->client->loginUser($alice);
        $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
    }

    public function testRegistrationWithInvitationTokenAndResetPasswordFlow(): void
    {
        $alice = $this->createUser('alice@example.com', 'Alice');

        $token = Uuid::v4();
        $invitation = new ContactRequest()
            ->setSender($alice)
            ->setRecipientEmail('invited@example.com')
            ->setInvitationToken($token)
            ->setStatus(ContactRequest::STATUS_PENDING);
        $this->em->persist($invitation);
        $this->em->flush();

        // 1. Registration without invitation fails validation
        $crawler = $this->client->request('GET', '/register');
        self::assertResponseIsSuccessful();
        $formNoInvite = $crawler->filter('form[name="registration_form"]')->form([
            'registration_form[firstname]' => 'Intrus',
            'registration_form[email]' => 'noinvite@example.com',
            'registration_form[plainPassword][first]' => 'password123',
            'registration_form[plainPassword][second]' => 'password123',
        ]);
        $this->client->submit($formNoInvite);
        self::assertResponseStatusCodeSame(422);

        // 2. Registration with valid invitation token succeeds and creates mutual shares
        $crawler = $this->client->request('GET', '/register?invitation_token=' . $token->toRfc4122());
        self::assertResponseIsSuccessful();
        $formInvite = $crawler->filter('form[name="registration_form"]')->form([
            'registration_form[firstname]' => 'Invité',
            'registration_form[lastname]' => 'Test',
            'registration_form[email]' => 'invited@example.com',
            'registration_form[plainPassword][first]' => 'password123',
            'registration_form[plainPassword][second]' => 'password123',
        ]);
        $this->client->submit($formInvite);
        self::assertResponseRedirects('/');

        // 3. Forgot password request & check-email page
        $resetCrawler = $this->client->request('GET', '/reset-password');
        self::assertResponseIsSuccessful();
        $resetForm = $resetCrawler->filter('form[name="reset_password_request_form"]')->form([
            'reset_password_request_form[email]' => 'alice@example.com',
        ]);
        $this->client->submit($resetForm);
        self::assertResponseRedirects('/reset-password/check-email');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }
}
