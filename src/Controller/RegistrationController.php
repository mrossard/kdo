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
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        UserAuthenticatorInterface $userAuthenticator,
        AppAuthenticator $authenticator,
        EntityManagerInterface $entityManager,
        ContactRequestRepository $contactRequestRepository,
    ): Response {
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Enforce: registration requires at least one invitation for the entered email
            $email = (string) $form->get('email')->getData();
            $hasInvitation = false;

            // Check invitation via token (must match the entered email)
            $invitationToken = $request->query->get('invitation_token');
            if ($invitationToken) {
                try {
                    $uuid = Uuid::fromString($invitationToken);
                    $matchingTokenInvitation = $contactRequestRepository->findOneBy([
                        'invitationToken' => $uuid,
                        'recipientEmail' => $email,
                        'status' => ContactRequest::STATUS_PENDING,
                        'recipient' => null,
                    ]);
                    if ($matchingTokenInvitation) {
                        $hasInvitation = true;
                    }
                } catch (\InvalidArgumentException $e) {
                    // ignore invalid token format here; the generic check below will apply
                }
            }

            // If no valid token-matching invitation, check for any pending invitation for this email
            if (!$hasInvitation) {
                $pendingCount = $contactRequestRepository->count([
                    'recipientEmail' => $email,
                    'recipient' => null,
                    'status' => ContactRequest::STATUS_PENDING,
                ]);
                if ($pendingCount > 0) {
                    $hasInvitation = true;
                }
            }

            if (!$hasInvitation) {
                $form->get('email')->addError(
                    new FormError(
                        'Vous ne pouvez vous inscrire que si vous avez reçu au moins une invitation pour cette adresse e-mail.',
                    ),
                );
                return $this->render('registration/register.html.twig', [
                    'registrationForm' => $form,
                ]);
            }

            // encode the plain password
            $user->setPassword($userPasswordHasher->hashPassword($user, $form->get('plainPassword')->getData()));

            $entityManager->persist($user);
            $entityManager->flush();
            // do anything else you need here, like send an email

            // Handle invitation token if present
            $invitationToken = $request->query->get('invitation_token');
            if ($invitationToken) {
                try {
                    $uuid = Uuid::fromString($invitationToken);
                    $contactRequest = $contactRequestRepository->findOneBy([
                        'invitationToken' => $uuid,
                        'recipientEmail' => $user->getEmail(),
                        'status' => ContactRequest::STATUS_PENDING,
                        'recipient' => null, // Ensure it's for an unregistered user
                    ]);

                    if ($contactRequest) {
                        $contactRequest->setRecipient($user);
                        $contactRequest->setStatus(ContactRequest::STATUS_ACCEPTED);
                        $contactRequest->setUpdatedAt(new \DateTimeImmutable());

                        // Créer des partages de liste de souhaits mutuels
                        $share1 = new WishlistShare();
                        $share1->setOwner($contactRequest->getSender());
                        $share1->setRecipient($contactRequest->getRecipient());
                        $entityManager->persist($share1);

                        $share2 = new WishlistShare();
                        $share2->setOwner($contactRequest->getRecipient());
                        $share2->setRecipient($contactRequest->getSender());
                        $entityManager->persist($share2);

                        $entityManager->flush(); // Flush again to save shares and updated request
                        $this->addFlash('success', 'Votre demande de contact a été automatiquement acceptée.');
                    }
                } catch (\InvalidArgumentException $e) {
                    // Invalid UUID format, ignore
                }
            }

            // Link any pending contact invitations by email to the newly created user
            // (covers the case when the user registers without using the invitation link)
            $pendingInvitations = $contactRequestRepository->findBy([
                'recipientEmail' => $user->getEmail(),
                'recipient' => null,
                'status' => ContactRequest::STATUS_PENDING,
            ]);

            if (!empty($pendingInvitations)) {
                foreach ($pendingInvitations as $pending) {
                    $pending->setRecipient($user);
                }
                $entityManager->flush();
                $this->addFlash('info', 'Vos invitations en attente ont été rattachées à votre compte.');
            }

            return $userAuthenticator->authenticateUser($user, $authenticator, $request);
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }
}
