<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TourControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connection = $this->em->getConnection();
        $connection->executeStatement(
            'TRUNCATE TABLE gift_idea_contribution, vote, comment, activity, project_read_status, reimbursement, gift_idea, gift_project_user, gift_project, wish, wishlist_share, contact_request, reset_password_request, "user" RESTART IDENTITY CASCADE',
        );
    }

    private function createUser(string $email, string $firstname): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setFirstname($firstname);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testTourRequiresAuthentication(): void
    {
        $this->client->request('GET', '/tour/modal/1');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/guide');
        self::assertResponseRedirects('/login');
    }

    public function testTourModalAndNavigation(): void
    {
        $user = $this->createUser('bob@example.com', 'Bob');
        $this->client->loginUser($user);

        // Step 1 full modal
        $crawler = $this->client->request('GET', '/tour/modal/1');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#kdo-tour-modal');
        self::assertSelectorTextContains('#kdo-tour-title', 'Visite guidée KDO');
        self::assertSelectorTextContains('.modal-card-body', 'Bienvenue sur KDO !');

        // Step 2 content only
        $this->client->request('GET', '/tour/modal/2?content_only=1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.modal-card-body', 'Ma Liste de souhaits (Wishlist)');
        self::assertSelectorTextContains('.tag', 'Étape 2 / 6');

        // Step 3
        $this->client->request('GET', '/tour/modal/3?content_only=1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.modal-card-body', 'Contacts & Entourage');

        // Step 4
        $this->client->request('GET', '/tour/modal/4?content_only=1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.modal-card-body', 'Projets Cadeaux Collaboratifs');

        // Step 5
        $this->client->request('GET', '/tour/modal/5?content_only=1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.modal-card-body', 'Cagnotte & Bilan Financier');

        // Step 6
        $this->client->request('GET', '/tour/modal/6?content_only=1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.modal-card-body', 'Notifications & C\'est parti !');
        self::assertSelectorTextContains('button.is-success', 'Terminer la visite');

        // Out-of-bounds clamped to valid range
        $this->client->request('GET', '/tour/modal/99?content_only=1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tag', 'Étape 6 / 6');

        $this->client->request('GET', '/tour/modal/0?content_only=1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tag', 'Étape 1 / 6');
    }

    public function testTourCompleteDismissAndReset(): void
    {
        $user = $this->createUser('alice@example.com', 'Alice');
        $this->client->loginUser($user);

        // Complete tour
        $this->client->request('POST', '/tour/complete');
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $updatedUser = $this->em->getRepository(User::class)->find($user->getId());
        self::assertNotNull($updatedUser);
        self::assertTrue($updatedUser->getUiPreferences()['tour_completed'] ?? false);

        // Reset tour via profile form (with CSRF token)
        $profileCrawler = $this->client->request('GET', '/profile');
        $resetForm = $profileCrawler->filter('form[action="/tour/reset"]')->form();
        $this->client->submit($resetForm);
        self::assertResponseRedirects('/profile');

        $this->em->clear();
        $updatedUser = $this->em->getRepository(User::class)->find($user->getId());
        self::assertNotNull($updatedUser);
        self::assertFalse($updatedUser->getUiPreferences()['tour_completed'] ?? true);

        // Dismiss tour
        $this->client->request('POST', '/tour/dismiss');
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $updatedUser = $this->em->getRepository(User::class)->find($user->getId());
        self::assertNotNull($updatedUser);
        self::assertTrue($updatedUser->getUiPreferences()['tour_completed'] ?? false);
    }

    public function testGuidePage(): void
    {
        $user = $this->createUser('carol@example.com', 'Carol');
        $this->client->loginUser($user);

        $this->client->request('GET', '/guide');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1.title', 'Guide complet des fonctionnalités KDO');
        self::assertSelectorExists('#section-dashboard');
        self::assertSelectorExists('#section-wishlist');
        self::assertSelectorExists('#section-contacts');
        self::assertSelectorExists('#section-projects');
        self::assertSelectorExists('#section-finance');
        self::assertSelectorExists('#section-notifications');
        self::assertSelectorExists('#section-faq');
    }

    public function testDashboardWelcomeBanner(): void
    {
        $user = $this->createUser('david@example.com', 'David');
        $this->client->loginUser($user);

        // Brand new user: banner is visible
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#kdo-tour-banner');

        // Once completed, banner is no longer displayed
        $prefs = $user->getUiPreferences() ?? [];
        $prefs['tour_completed'] = true;
        $user->setUiPreferences($prefs);
        $this->em->flush();

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#kdo-tour-banner');
    }

    public function testInteractiveTourConfigAndTargetsExistOnPages(): void
    {
        $user = $this->createUser('eve@example.com', 'Eve');
        $this->client->loginUser($user);

        // Dashboard
        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#kdo-tour-config');
        self::assertSelectorExists('[data-tour-start]');
        self::assertSelectorExists('[data-tour="dashboard-kpis"]');
        self::assertSelectorExists('[data-tour="dashboard-quick-actions"]');
        self::assertSelectorExists('[data-tour="navbar-notifications"]');

        $configJson = $crawler->filter('#kdo-tour-config')->text();
        $config = json_decode($configJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('completeUrl', $config);
        self::assertArrayHasKey('routes', $config);
        self::assertSame(['home', 'wish', 'contact', 'project', 'financial'], array_keys($config['routes']));

        // Wishlist
        $this->client->request('GET', $config['routes']['wish']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-tour="wish-tabs"]');
        self::assertSelectorExists('[data-tour="wish-add-btn"]');

        // Contacts
        $this->client->request('GET', $config['routes']['contact']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-tour="contact-invite"]');
        self::assertSelectorExists('[data-tour="contact-list"]');

        // Projects
        $this->client->request('GET', $config['routes']['project']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-tour="project-new-btn"]');
        self::assertSelectorExists('[data-tour="project-archives-btn"]');

        // Financials
        $this->client->request('GET', $config['routes']['financial']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-tour="financial-summary"]');
    }
}
