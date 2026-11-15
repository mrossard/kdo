<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContactRequest;
use App\Entity\User;
use App\Entity\Wish;
use App\Entity\WishlistShare;
use App\Form\AddContactFormType;
use App\Repository\ContactRequestRepository;
use App\Repository\ManagedPersonRepository;
use App\Repository\UserRepository;
use App\Repository\WishlistShareRepository;
use App\Repository\WishRepository;
use App\Service\ActivityLogger;
use App\Service\FinancialService;
use App\Service\ProjectMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

// @mago-expect lint:cyclomatic-complexity,kan-defect
#[Route('/contact')]
#[IsGranted('ROLE_USER')]
final class ContactController extends AbstractHtmxController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WishlistShareRepository $wishlistShareRepository,
        private readonly ContactRequestRepository $contactRequestRepository,
        private readonly FinancialService $financialService,
        private readonly ProjectMailer $projectMailer,
    ) {}

    #[Route('/', name: 'app_contact_index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        UserRepository $userRepository,
        ManagedPersonRepository $managedPersonRepository,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $addContactForm = $this->createForm(AddContactFormType::class);
        $addContactForm->handleRequest($request);

        if ($addContactForm->isSubmitted() && $addContactForm->isValid()) {
            /** @var string|null $rawEmail */
            $rawEmail = $addContactForm->get('email')->getData();
            $email = $rawEmail ?? '';
            $this->handleAddContactSubmission($user, $email, $userRepository);

            return $this->redirectToRoute('app_contact_index');
        }

        $myContacts = $this->wishlistShareRepository->findMutualContactsForUser($user);

        /** @var array<int, float> $contactBalances */
        $contactBalances = [];
        foreach ($myContacts as $contact) {
            $contactId = $contact->getId();
            if ($contactId === null) {
                continue;
            }
            $contactBalances[$contactId] = $this->financialService->getContactCumulativeBalance($user, $contact);
        }

        $sentRequests = $this->contactRequestRepository->findPendingSentByUser($user);
        $receivedRequests = $this->contactRequestRepository->findPendingReceivedByUser($user);

        return $this->render('contact/index.html.twig', [
            'add_contact_form' => $addContactForm,
            'my_contacts' => $myContacts,
            'managed_persons_by_manager' => $managedPersonRepository->findByManagersGrouped($myContacts),
            'sent_requests' => $sentRequests,
            'received_requests' => $receivedRequests,
            'contact_balances' => $contactBalances,
        ]);
    }

    #[Route('/request/{id}/accept', name: 'app_contact_request_accept', methods: ['POST'])]
    public function acceptRequest(Request $request, ContactRequest $contactRequest): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (
            $contactRequest->getRecipient() !== $user
            || $contactRequest->getStatus() !== ContactRequest::STATUS_PENDING
        ) {
            throw $this->createAccessDeniedException('Impossible d´accepter cette demande.');
        }

        if ($contactRequest->getRecipient() === null && $contactRequest->getRecipientEmail()) {
            throw $this->createAccessDeniedException(
                'Cette demande d´invitation doit être acceptée via le lien d´inscription.',
            );
        }

        if ($this->isCsrfTokenValid(
            'accept_request' . (string) $contactRequest->getId(),
            $request->getPayload()->getString('_token'),
        )) {
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

            $senderName = $contactRequest->getSender()?->getDisplayName() ?? '';
            $this->addFlash('success', 'Demande de contact acceptée avec ' . $senderName . '.');
        }

        return $this->redirectToRoute('app_contact_index');
    }

    #[Route('/request/{id}/decline', name: 'app_contact_request_decline', methods: ['POST'])]
    public function declineRequest(Request $request, ContactRequest $contactRequest): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (
            $contactRequest->getRecipient() !== $user
            || $contactRequest->getStatus() !== ContactRequest::STATUS_PENDING
        ) {
            throw $this->createAccessDeniedException('Impossible de refuser cette demande.');
        }

        if ($this->isCsrfTokenValid(
            'decline_request' . (string) $contactRequest->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            $contactRequest->setStatus(ContactRequest::STATUS_DECLINED);
            $contactRequest->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('info', 'Demande de contact refusée.');
        }

        return $this->redirectToRoute('app_contact_index');
    }

    #[Route('/remove/{id}', name: 'app_contact_remove', methods: ['POST'])]
    public function removeContact(Request $request, User $contactUser): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $share1 = $this->wishlistShareRepository->findOneBy(['owner' => $user, 'recipient' => $contactUser]);
        $share2 = $this->wishlistShareRepository->findOneBy(['owner' => $contactUser, 'recipient' => $user]);

        if (!$share1 && !$share2) {
            throw $this->createAccessDeniedException('Ce contact n´existe pas ou n´est pas mutuel.');
        }

        if ($this->isCsrfTokenValid(
            'remove_contact' . (string) $contactUser->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            if ($share1 !== null) {
                $this->entityManager->remove($share1);
            }
            if ($share2 !== null) {
                $this->entityManager->remove($share2);
            }

            $requests = $this->contactRequestRepository->findBetweenUsers($user, $contactUser);

            foreach ($requests as $req) {
                $this->entityManager->remove($req);
            }

            $this->entityManager->flush();

            $this->addFlash('success', 'Contact ' . $contactUser->getDisplayName() . ' supprimé.');
        }

        return $this->redirectToRoute('app_contact_index');
    }

    #[Route('/settle/{id}', name: 'app_contact_settle_debt', methods: ['POST'])]
    public function settleDebt(Request $request, User $contactUser, ActivityLogger $activityLogger): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        if (!$this->isCsrfTokenValid(
            'settle_debt' . (string) $contactUser->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            return $this->redirectToRoute('app_contact_index');
        }

        $count = $this->financialService->settleDebts(
            $contactUser,
            $currentUser,
            $this->entityManager,
            $activityLogger,
        );
        if ($count === 0) {
            $this->addFlash('info', 'Aucune dette à régler trouvée.');

            return $this->redirectToRoute('app_contact_index');
        }

        $this->addFlash('success', "Dettes réglées pour {$count} projet(s).");

        return $this->redirectToRoute('app_contact_index');
    }

    #[Route('/view/{id}', name: 'app_contact_view', methods: ['GET'])]
    public function viewContactListeDeSouhaits(
        User $owner,
        WishRepository $wishRepository,
        ManagedPersonRepository $managedPersonRepository,
    ): Response {
        /** @var User $me */
        $me = $this->getUser();

        $share = $this->wishlistShareRepository->findOneBy([
            'owner' => $owner,
            'recipient' => $me,
        ]);

        if (!$share && $owner !== $me) {
            throw $this->createAccessDeniedException("Vous n'avez pas accès à cette liste.");
        }

        return $this->render('contact/view.html.twig', [
            'owner' => $owner,
            'managed_person' => null,
            'managed_persons' => $managedPersonRepository->findByManager($owner),
            'wishes' => $wishRepository->findUndeliveredUserWishesWithGiftIdeaDetails($owner),
        ]);
    }

    #[Route('/wish/{id}/toggle-reserve', name: 'app_contact_wish_toggle_reserve', methods: ['POST'])]
    public function toggleReserveWish(Wish $wish, Request $request): Response
    {
        /** @var User $me */
        $me = $this->getUser();
        $managers = $wish->getAllManagers();
        $managedPerson = $wish->getManagedPerson();

        if ($managers === [] || $wish->isManagedBy($me)) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas réserver votre propre souhait.');
        }

        $hasShare = $managedPerson !== null
            ? $this->wishlistShareRepository->hasShareWithAnyManager($me, $managedPerson)
            : $this->wishlistShareRepository->findOneBy(['owner' => $managers[0], 'recipient' => $me]) !== null;
        if (!$hasShare) {
            throw $this->createAccessDeniedException("Vous n'avez pas accès à la liste de souhaits de ce contact.");
        }

        if ($this->isCsrfTokenValid(
            'toggle_reserve_wish' . (string) $wish->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            if ($wish->getActiveProject() !== null) {
                $this->addFlash('warning', 'Ce souhait fait déjà partie d\'un projet cadeau en cours.');
            }
            if ($wish->getActiveProject() === null) {
                $currentReserver = $wish->getReservedBy();
                if ($currentReserver !== null && $currentReserver !== $me) {
                    $this->addFlash(
                        'warning',
                        'Ce souhait est déjà réservé par ' . $currentReserver->getDisplayName() . '.',
                    );
                }
                if ($currentReserver === $me) {
                    $wish->setReservedBy(null);
                    $wish->setReservedAt(null);
                    $this->entityManager->flush();
                    $this->addFlash('info', "Réservation annulée pour « {$wish->getName()} ».");
                }
                if ($currentReserver === null) {
                    $wish->setReservedBy($me);
                    $wish->setReservedAt(new \DateTimeImmutable());
                    $this->entityManager->flush();
                    $this->addFlash('success', "Vous avez réservé « {$wish->getName()} » !");
                }
            }
        }

        if ($managedPerson !== null) {
            return $this->redirectOrHtmx($request, 'app_contact_managed_view', ['id' => $managedPerson->getId()]);
        }

        return $this->redirectOrHtmx($request, 'app_contact_view', ['id' => $managers[0]->getId()]);
    }

    private function handleAddContactSubmission(User $user, string $email, UserRepository $userRepository): void
    {
        $recipient = $userRepository->findOneBy(['email' => $email]);
        if ($recipient === $user) {
            $this->addFlash('warning', 'Vous ne pouvez pas vous ajouter vous-même comme contact.');

            return;
        }

        $existingCriteria = $recipient !== null
            ? ['sender' => $user, 'recipient' => $recipient, 'status' => ContactRequest::STATUS_PENDING]
            : ['sender' => $user, 'recipientEmail' => $email, 'status' => ContactRequest::STATUS_PENDING];

        if ($this->contactRequestRepository->findOneBy($existingCriteria) !== null) {
            $this->addFlash('info', 'Une demande de contact est déjà en attente pour cet utilisateur.');

            return;
        }

        if ($recipient !== null) {
            $reverseRequest = $this->contactRequestRepository->findOneBy([
                'sender' => $recipient,
                'recipient' => $user,
                'status' => ContactRequest::STATUS_PENDING,
            ]);
            if ($reverseRequest !== null) {
                $this->addFlash(
                    'info',
                    'Cet utilisateur vous a déjà envoyé une demande de contact. Vous pouvez l´accepter ci-dessous.',
                );

                return;
            }

            $share1 = $this->wishlistShareRepository->findOneBy(['owner' => $user, 'recipient' => $recipient]);
            $share2 = $this->wishlistShareRepository->findOneBy(['owner' => $recipient, 'recipient' => $user]);
            if ($share1 !== null && $share2 !== null) {
                $this->addFlash('info', 'Vous êtes déjà en contact avec cet utilisateur.');

                return;
            }
        }

        $this->sendContactInvitation($user, $email, $recipient);
    }

    private function sendContactInvitation(User $user, string $email, ?User $recipient): void
    {
        $contactRequest = new ContactRequest();
        $contactRequest->setSender($user);
        $token = null;

        if ($recipient !== null) {
            $contactRequest->setRecipient($recipient);
            $this->addFlash('success', 'Demande de contact envoyée à ' . $recipient->getDisplayName() . '.');
        }

        if ($recipient === null) {
            $contactRequest->setRecipientEmail($email);
            $token = Uuid::v4();
            $contactRequest->setInvitationToken($token);
            $this->addFlash(
                'success',
                'Invitation envoyée à ' . $email . '. Un email a été envoyé pour l´inviter à s´inscrire.',
            );
        }

        $this->entityManager->persist($contactRequest);
        $this->entityManager->flush();
        $this->projectMailer->sendContactRequestEmail($user, $email, $recipient, $token);
    }
}
