<?php

namespace App\Controller;

use App\Entity\Activity;
use App\Entity\ContactRequest;
use App\Entity\User;
use App\Entity\WishlistShare;
use App\Form\AddContactFormType;
use App\Repository\ContactRequestRepository;
use App\Repository\GiftProjectRepository;
use App\Repository\UserRepository;
use App\Repository\WishlistShareRepository;
use App\Service\ActivityLogger;
use App\Service\FinancialService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/contact')]
#[IsGranted('ROLE_USER')]
class ContactController extends AbstractController
{
    #[Route('/', name: 'app_contact_index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        WishlistShareRepository $wishlistShareRepository,
        ContactRequestRepository $contactRequestRepository,
        MailerInterface $mailer,
        FinancialService $financialService,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        // Handle Add Contact Form
        $addContactForm = $this->createForm(AddContactFormType::class);
        $addContactForm->handleRequest($request);

        if ($addContactForm->isSubmitted() && $addContactForm->isValid()) {
            $email = $addContactForm->get('email')->getData();
            $recipient = $userRepository->findOneBy(['email' => $email]);

            if ($recipient === $user) {
                $this->addFlash('warning', 'Vous ne pouvez pas vous ajouter vous-même comme contact.');
            } else {
                // Check for existing request or contact
                $existingRequest = null;
                if ($recipient) { // If registered user
                    $existingRequest = $contactRequestRepository->findOneBy([
                        'sender' => $user,
                        'recipient' => $recipient,
                        'status' => ContactRequest::STATUS_PENDING,
                    ]);
                } else { // If unregistered user
                    $existingRequest = $contactRequestRepository->findOneBy([
                        'sender' => $user,
                        'recipientEmail' => $email,
                        'status' => ContactRequest::STATUS_PENDING,
                    ]);
                }

                $reverseRequest = null;
                if ($recipient) {
                    $reverseRequest = $contactRequestRepository->findOneBy([
                        'sender' => $recipient,
                        'recipient' => $user,
                        'status' => ContactRequest::STATUS_PENDING,
                    ]);
                }

                $mutualShareExist = false;
                if ($recipient) {
                    $share1 = $wishlistShareRepository->findOneBy(['owner' => $user, 'recipient' => $recipient]);
                    $share2 = $wishlistShareRepository->findOneBy(['owner' => $recipient, 'recipient' => $user]);
                    if ($share1 && $share2) {
                        $mutualShareExist = true;
                    }
                }

                if ($existingRequest) {
                    $this->addFlash('info', 'Une demande de contact est déjà en attente pour cet utilisateur.');
                } elseif ($reverseRequest) {
                    $this->addFlash(
                        'info',
                        'Cet utilisateur vous a déjà envoyé une demande de contact. Vous pouvez l´accepter ci-dessous.',
                    );
                } elseif ($mutualShareExist) {
                    $this->addFlash('info', 'Vous êtes déjà en contact avec cet utilisateur.');
                } else {
                    $contactRequest = new ContactRequest();
                    $contactRequest->setSender($user);

                    $emailMessage = new Email()->to($email)->subject('Nouvelle demande de contact sur KDO');

                    if ($recipient) {
                        $contactRequest->setRecipient($recipient);
                        $emailMessage->text(
                            "Bonjour,\n\n{$user->getDisplayName()} souhaite vous ajouter à ses contacts sur KDO.\nConnectez-vous pour accepter la demande : "
                                . $this->generateUrl('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL),
                        );
                        $this->addFlash(
                            'success',
                            'Demande de contact envoyée à ' . $recipient->getDisplayName() . '.',
                        );
                    } else {
                        $contactRequest->setRecipientEmail($email);
                        $token = Uuid::v4();
                        $contactRequest->setInvitationToken($token);

                        $registrationUrl = $this->generateUrl(
                            'app_register',
                            ['invitation_token' => $token],
                            UrlGeneratorInterface::ABSOLUTE_URL,
                        );
                        $emailMessage->text(
                            "Bonjour,\n\n{$user->getDisplayName()} vous invite à rejoindre KDO pour partager vos listes de cadeaux.\n\nCliquez sur le lien suivant pour vous inscrire et accepter la demande :\n{$registrationUrl}",
                        );

                        $this->addFlash(
                            'success',
                            'Invitation envoyée à ' . $email . '. Un email a été envoyé pour l´inviter à s´inscrire.',
                        );
                    }

                    $entityManager->persist($contactRequest);
                    $entityManager->flush();

                    $mailer->send($emailMessage);
                }
            }

            return $this->redirectToRoute('app_contact_index');
        }

        // Obtenir les contacts mutuels de l'utilisateur actuel (Partage de liste de souhaits)
        $myContacts = $this->getMutualContacts($user, $wishlistShareRepository);

        $contactBalances = [];
        foreach ($myContacts as $contact) {
            $contactBalances[$contact->getId()] = $financialService->getContactCumulativeBalance($user, $contact);
        }

        // Get pending requests (sent by me, or sent to me)
        $sentRequests = $contactRequestRepository
            ->createQueryBuilder('cr')
            ->where('cr.sender = :user')
            ->andWhere('cr.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', ContactRequest::STATUS_PENDING)
            ->getQuery()
            ->getResult();

        $receivedRequests = $contactRequestRepository
            ->createQueryBuilder('cr')
            ->where('cr.recipient = :user')
            ->andWhere('cr.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', ContactRequest::STATUS_PENDING)
            ->getQuery()
            ->getResult();

        return $this->render('contact/index.html.twig', [
            'add_contact_form' => $addContactForm,
            'my_contacts' => $myContacts,
            'sent_requests' => $sentRequests,
            'received_requests' => $receivedRequests,
            'contact_balances' => $contactBalances,
        ]);
    }

    #[Route('/request/{id}/accept', name: 'app_contact_request_accept', methods: ['POST'])]
    public function acceptRequest(
        Request $request,
        ContactRequest $contactRequest,
        EntityManagerInterface $entityManager,
        WishlistShareRepository $wishlistShareRepository,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if (
            $contactRequest->getRecipient() !== $user
            || $contactRequest->getStatus() !== ContactRequest::STATUS_PENDING
        ) {
            throw $this->createAccessDeniedException('Impossible d´accepter cette demande.');
        }
        // If recipientEmail is set but recipient is null, it means the token was used.
        // This case should not be accepted via this route, but rather upon registration.
        if ($contactRequest->getRecipient() === null && $contactRequest->getRecipientEmail()) {
            throw $this->createAccessDeniedException(
                'Cette demande d´invitation doit être acceptée via le lien d´inscription.',
            );
        }

        if ($this->isCsrfTokenValid('accept_request' . $contactRequest->getId(), $request->request->get('_token'))) {
            $contactRequest->setStatus(ContactRequest::STATUS_ACCEPTED);
            $contactRequest->setUpdatedAt(new \DateTimeImmutable());

            // Créer des partages de liste de souhaits mutuels
            // L'expéditeur partage avec le destinataire
            $share1 = new WishlistShare();
            $share1->setOwner($contactRequest->getSender());
            $share1->setRecipient($contactRequest->getRecipient());
            $entityManager->persist($share1);

            // Recipient shares with Sender (mutual contact)
            $share2 = new WishlistShare();
            $share2->setOwner($contactRequest->getRecipient());
            $share2->setRecipient($contactRequest->getSender());
            $entityManager->persist($share2);

            $entityManager->flush();

            $this->addFlash(
                'success',
                'Demande de contact acceptée avec ' . $contactRequest->getSender()->getDisplayName() . '.',
            );
        }

        return $this->redirectToRoute('app_contact_index');
    }

    #[Route('/request/{id}/decline', name: 'app_contact_request_decline', methods: ['POST'])]
    public function declineRequest(
        Request $request,
        ContactRequest $contactRequest,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if (
            $contactRequest->getRecipient() !== $user
            || $contactRequest->getStatus() !== ContactRequest::STATUS_PENDING
        ) {
            throw $this->createAccessDeniedException('Impossible de refuser cette demande.');
        }

        if ($this->isCsrfTokenValid('decline_request' . $contactRequest->getId(), $request->request->get('_token'))) {
            $contactRequest->setStatus(ContactRequest::STATUS_DECLINED);
            $contactRequest->setUpdatedAt(new \DateTimeImmutable());
            $entityManager->flush();

            $this->addFlash('info', 'Demande de contact refusée.');
        }

        return $this->redirectToRoute('app_contact_index');
    }

    #[Route('/remove/{id}', name: 'app_contact_remove', methods: ['POST'])]
    public function removeContact(
        Request $request,
        User $contactUser,
        EntityManagerInterface $entityManager,
        WishlistShareRepository $wishlistShareRepository,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        // Find and remove mutual shares
        $share1 = $wishlistShareRepository->findOneBy(['owner' => $user, 'recipient' => $contactUser]);
        $share2 = $wishlistShareRepository->findOneBy(['owner' => $contactUser, 'recipient' => $user]);

        if (!$share1 && !$share2) {
            throw $this->createAccessDeniedException('Ce contact n´existe pas ou n´est pas mutuel.');
        }

        if ($this->isCsrfTokenValid('remove_contact' . $contactUser->getId(), $request->request->get('_token'))) {
            if ($share1) {
                $entityManager->remove($share1);
            }
            if ($share2) {
                $entityManager->remove($share2);
            }

            // Also remove any pending/accepted/declined ContactRequests between them to clean up
            $requests = $entityManager
                ->getRepository(ContactRequest::class)
                ->createQueryBuilder('cr')
                ->where(
                    '(cr.sender = :user AND cr.recipient = :contactUser) OR (cr.sender = :contactUser AND cr.recipient = :user)',
                )
                ->setParameter('user', $user)
                ->setParameter('contactUser', $contactUser)
                ->getQuery()
                ->getResult();

            foreach ($requests as $req) {
                $entityManager->remove($req);
            }

            $entityManager->flush();

            $this->addFlash('success', 'Contact ' . $contactUser->getDisplayName() . ' supprimé.');
        }

        return $this->redirectToRoute('app_contact_index');
    }

    #[Route('/settle/{id}', name: 'app_contact_settle_debt', methods: ['POST'])]
    public function settleDebt(
        Request $request,
        User $contactUser,
        EntityManagerInterface $entityManager,
        FinancialService $financialService,
        ActivityLogger $activityLogger,
        GiftProjectRepository $projectRepository,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        if ($this->isCsrfTokenValid('settle_debt' . $contactUser->getId(), $request->request->get('_token'))) {
            $count = $financialService->settleDebts($contactUser, $currentUser, $entityManager);

            if ($count > 0) {
                $projects = $projectRepository->findProjectsInvolvingUsers($currentUser, $contactUser);
                foreach ($projects as $project) {
                    $activityLogger->log($project, Activity::TYPE_REIMBURSEMENT_CREATED, $currentUser, [
                        'amount' => 'régler',
                    ]);
                }
                $entityManager->flush();
                $this->addFlash('success', "Dettes réglées pour {$count} projet(s).");
            } else {
                $this->addFlash('info', 'Aucune dette à régler trouvée.');
            }
        }

        return $this->redirectToRoute('app_contact_index');
    }

    #[Route('/view/{id}', name: 'app_contact_view', methods: ['GET'])]
    public function viewContactListeDeSouhaits(User $owner, WishlistShareRepository $wishlistShareRepository): Response
    {
        /** @var User $me */
        $me = $this->getUser();

        // Check if $me has access to $owner's list (i.e., $owner shares with $me)
        $share = $wishlistShareRepository->findOneBy([
            'owner' => $owner,
            'recipient' => $me,
        ]);

        if (!$share && $owner !== $me) { // Allow viewing own list via this route too
            throw $this->createAccessDeniedException("Vous n'avez pas accès à cette liste.");
        }

        return $this->render('contact/view.html.twig', [
            'owner' => $owner,
            'wishes' => $owner->getWishes(),
        ]);
    }

    private function getMutualContacts(User $user, WishlistShareRepository $wishlistShareRepository): array
    {
        $qb = $wishlistShareRepository->createQueryBuilder('ws');
        $contacts = $qb
            ->where('ws.owner = :user OR ws.recipient = :user')
            ->andWhere('ws.owner != ws.recipient')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult(); // Ensure it's not a self-share (shouldn't happen)

        $mutualContacts = [];
        foreach ($contacts as $share) {
            $otherUser = $share->getOwner() === $user ? $share->getRecipient() : $share->getOwner();
            // Check if a reverse share exists (mutual contact)
            $reverseShare = $wishlistShareRepository->findOneBy([
                'owner' => $otherUser,
                'recipient' => $user,
            ]);

            // Add only if mutual
            if ($reverseShare && !isset($mutualContacts[$otherUser->getId()])) {
                $mutualContacts[$otherUser->getId()] = $otherUser;
            }
        }

        // Sort by display name (or email if no display name)
        uasort($mutualContacts, static function (User $a, User $b) {
            return strcmp($a->getDisplayName(), $b->getDisplayName());
        });

        return array_values($mutualContacts);
    }
}
