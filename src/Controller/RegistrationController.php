<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContactRequest;
use App\Entity\User;
use App\Entity\WishlistShare;
use App\Form\RegistrationFormType;
use App\Repository\ContactRequestRepository;
use App\Security\AppAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use Symfony\Component\Uid\Uuid;

final class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContactRequestRepository $contactRequestRepository,
    ) {}

    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        UserAuthenticatorInterface $userAuthenticator,
        AppAuthenticator $authenticator,
    ): Response {
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string|null $rawEmail */
            $rawEmail = $form->get('email')->getData();
            $email = $rawEmail ?? '';
            $invitationToken = $request->query->get('invitation_token');

            if (!$this->hasValidInvitation($email, $invitationToken)) {
                $form->get('email')->addError(
                    new FormError(
                        'Vous ne pouvez vous inscrire que si vous avez reçu au moins une invitation pour cette adresse e-mail.',
                    ),
                );

                return $this->render('registration/register.html.twig', [
                    'registrationForm' => $form,
                ]);
            }

            /** @var string|null $rawPassword */
            $rawPassword = $form->get('plainPassword')->getData();
            $plainPassword = $rawPassword ?? '';
            $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $this->processInvitationsOnRegistration($user, $invitationToken);

            return (
                $userAuthenticator->authenticateUser($user, $authenticator, $request) ?? $this->redirectToRoute(
                    'app_home',
                )
            );
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    private function hasValidInvitation(string $email, #[\SensitiveParameter] ?string $invitationToken): bool
    {
        if ($invitationToken !== null && Uuid::isValid($invitationToken)) {
            $matchingTokenInvitation = $this->contactRequestRepository->findOneBy([
                'invitationToken' => Uuid::fromString($invitationToken),
                'recipientEmail' => $email,
                'status' => ContactRequest::STATUS_PENDING,
                'recipient' => null,
            ]);
            if ($matchingTokenInvitation !== null) {
                return true;
            }
        }

        return $this->contactRequestRepository->count([
            'recipientEmail' => $email,
            'recipient' => null,
            'status' => ContactRequest::STATUS_PENDING,
        ]) > 0;
    }

    private function processInvitationsOnRegistration(User $user, #[\SensitiveParameter] ?string $invitationToken): void
    {
        if ($invitationToken !== null && Uuid::isValid($invitationToken)) {
            $contactRequest = $this->contactRequestRepository->findOneBy([
                'invitationToken' => Uuid::fromString($invitationToken),
                'recipientEmail' => $user->getEmail(),
                'status' => ContactRequest::STATUS_PENDING,
                'recipient' => null,
            ]);

            if ($contactRequest !== null) {
                $contactRequest->setRecipient($user);
                $contactRequest->setStatus(ContactRequest::STATUS_ACCEPTED);
                $contactRequest->setUpdatedAt(new \DateTimeImmutable());

                $share1 = new WishlistShare();
                $share1->setOwner($contactRequest->getSender());
                $share1->setRecipient($contactRequest->getRecipient());
                $this->entityManager->persist($share1);

                $share2 = new WishlistShare();
                $share2->setOwner($contactRequest->getRecipient());
                $share2->setRecipient($contactRequest->getSender());
                $this->entityManager->persist($share2);

                $this->entityManager->flush();
                $this->addFlash('success', 'Votre demande de contact a été automatiquement acceptée.');
            }
        }

        $pendingInvitations = $this->contactRequestRepository->findBy([
            'recipientEmail' => $user->getEmail(),
            'recipient' => null,
            'status' => ContactRequest::STATUS_PENDING,
        ]);

        if ($pendingInvitations !== []) {
            foreach ($pendingInvitations as $pending) {
                $pending->setRecipient($user);
            }
            $this->entityManager->flush();
            $this->addFlash('info', 'Vos invitations en attente ont été rattachées à votre compte.');
        }
    }
}
