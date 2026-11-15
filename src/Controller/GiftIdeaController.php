<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Activity;
use App\Entity\GiftIdea;
use App\Entity\GiftIdeaContribution;
use App\Entity\GiftProject;
use App\Entity\ManagedPerson;
use App\Entity\User;
use App\Entity\Vote;
use App\Entity\Wish;
use App\Enum\GiftIdeaStatus;
use App\Form\GiftIdeaType;
use App\Repository\WishlistShareRepository;
use App\Security\Voter\GiftIdeaVoter;
use App\Security\Voter\GiftProjectVoter;
use App\Service\ActivityLogger;
use App\Service\UrlMetadataFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// @mago-expect lint:cyclomatic-complexity,kan-defect,too-many-methods
#[Route('/project')]
#[IsGranted('ROLE_USER')]
final class GiftIdeaController extends AbstractProjectController
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/{id}/new-idea-form', name: 'app_gift_idea_new_form', methods: ['GET'])]
    public function newGiftIdeaForm(GiftProject $project, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->denyUnlessCanViewProject($project, $user);

        $denied = $this->denyWhenDelivered($project, $request, 'ideas');
        if ($denied !== null) {
            return $denied;
        }

        $giftIdea = new GiftIdea();
        $giftIdeaForm = $this->createForm(GiftIdeaType::class, $giftIdea);

        return $this->render('gift_project/_gift_idea_form.html.twig', [
            'project' => $project,
            'gift_idea_form' => $giftIdeaForm,
        ]);
    }

    #[Route('/{id}/idea-autofill', name: 'app_gift_idea_autofill', methods: ['POST'])]
    public function autofillIdeaForm(
        GiftProject $project,
        Request $request,
        UrlMetadataFetcher $metadataFetcher,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $this->denyUnlessCanViewProject($project, $user);

        $denied = $this->denyWhenDelivered($project, $request, 'ideas');
        if ($denied !== null) {
            return $denied;
        }

        $allParams = $request->request->all();
        /** @var array<string, string|null> $ideaData */
        $ideaData = is_array($allParams['gift_idea'] ?? null) ? $allParams['gift_idea'] : [];
        $giftIdea = new GiftIdea();
        $result = $metadataFetcher->populateFromFormData($giftIdea, $ideaData);
        $giftIdeaForm = $this->createForm(GiftIdeaType::class, $giftIdea);

        return $this->render('gift_project/_gift_idea_form.html.twig', [
            'project' => $project,
            'gift_idea_form' => $giftIdeaForm,
            'autofill_message' => $result['message'],
            'autofill_status' => $result['status'],
        ]);
    }

    #[Route('/{id}/copy-wish/{wish_id}', name: 'app_project_copy_wish', methods: ['POST'])]
    public function copyWishToIdea(
        GiftProject $project,
        #[MapEntity(id: 'wish_id')]
        Wish $wish,
        Request $request,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $this->denyUnlessCanViewProject($project, $user, 'Surprise ! Vous ne pouvez pas modifier ce projet.');

        $denied = $this->denyWhenDelivered($project, $request, 'liste_souhaits');
        if ($denied !== null) {
            return $denied;
        }

        foreach ($project->getGiftIdeas() as $idea) {
            if ($idea->getFromWish() !== $wish) {
                continue;
            }

            $this->addFlash('warning', 'Ce souhait a déjà été copié dans les idées cadeaux.');

            if ($request->headers->get('HX-Request')) {
                return new Response(null, 204, [
                    'HX-Redirect' => $this->generateUrl('app_project_show', [
                        'id' => $project->getId(),
                        'tab' => 'liste_souhaits',
                    ]),
                ]);
            }

            return $this->redirectToRoute('app_project_show', [
                'id' => $project->getId(),
                'tab' => 'liste_souhaits',
            ]);
        }

        $reservedBy = $wish->getReservedBy();
        if ($reservedBy !== null && $reservedBy !== $user) {
            $this->addFlash('warning', 'Ce souhait est déjà réservé par ' . $reservedBy->getDisplayName() . '.');

            return $this->redirectToRoute('app_project_show', [
                'id' => $project->getId(),
                'tab' => 'liste_souhaits',
            ]);
        }

        if ($this->isCsrfTokenValid(
            'copy_wish' . (string) $wish->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            if ($reservedBy === $user) {
                $wish->setReservedBy(null);
                $wish->setReservedAt(null);
            }
            $giftIdea = GiftIdea::createFromWish($wish, $project, $user);

            $this->activityLogger->log($project, Activity::TYPE_IDEA_ADDED, $user, [
                'idea_name' => $giftIdea->getName(),
            ]);

            $this->entityManager->persist($giftIdea);
            $this->entityManager->flush();

            $this->addFlash('success', 'Souhait copié en idée cadeau !');

            return $this->redirectOrHtmx($request, 'app_project_show', [
                'id' => $project->getId(),
                'tab' => 'ideas',
            ]);
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
    }

    #[Route('/idea/{id}/mark-purchased', name: 'app_gift_idea_mark_purchased', methods: ['POST'])]
    public function markIdeaPurchased(Request $request, GiftIdea $giftIdea): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $project = $this->requireIdeaProject($giftIdea);
        $this->denyUnlessCanViewProject($project, $user);

        $denied = $this->denyWhenDelivered($project, $request, 'ideas');
        if ($denied !== null) {
            return $denied;
        }

        if (!$project->isLocked()) {
            $this->addFlash('warning', 'Le projet doit être verrouillé pour marquer des achats.');

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
        }

        if ($giftIdea->getStatus() !== GiftIdea::STATUS_SELECTED) {
            $this->addFlash('warning', 'Seules les idées validées peuvent être achetées.');

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
        }

        $existingBuyer = $giftIdea->getPurchasedBy();
        if ($existingBuyer !== null && $existingBuyer !== $user && $project->getOwner() !== $user) {
            $this->addFlash('warning', 'Ce cadeau a déjà été acheté.');

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
        }

        $price = $request->getPayload()->getString('price');
        if ($price === '' || !is_numeric($price) || (float) $price <= 0) {
            $this->addFlash('warning', 'Le prix est obligatoire et doit être supérieur à 0.');

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
        }

        $formattedPrice = number_format((float) $price, 2, '.', '');

        if ($this->isCsrfTokenValid(
            'mark_purchased' . (string) $giftIdea->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            if ($existingBuyer === null) {
                $giftIdea->setPurchasedBy($user);
                $giftIdea->setPurchasedAt(new \DateTimeImmutable());
            }
            $giftIdea->setPurchasePrice($formattedPrice);
            $this->activityLogger->log($project, Activity::TYPE_IDEA_PURCHASED, $user, [
                'idea_name' => $giftIdea->getName(),
                'price' => $formattedPrice,
            ]);
            $this->entityManager->flush();
            $this->addFlash(
                'success',
                $existingBuyer === null ? 'Cadeau marqué comme acheté !' : 'Prix d\'achat mis à jour.',
            );

            if ($request->headers->get('HX-Request')) {
                return new Response(null, 204, [
                    'HX-Redirect' => $this->generateUrl('app_project_show', [
                        'id' => $project->getId(),
                        'tab' => 'ideas',
                    ]),
                ]);
            }
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
    }

    #[Route('/idea/{id}/unmark-purchased', name: 'app_gift_idea_unmark_purchased', methods: ['POST'])]
    public function unmarkIdeaPurchased(Request $request, GiftIdea $giftIdea): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $project = $this->requireIdeaProject($giftIdea);
        $this->denyUnlessCanViewProject($project, $user);

        $denied = $this->denyWhenDelivered($project, $request, 'ideas');
        if ($denied !== null) {
            return $denied;
        }

        if ($giftIdea->getPurchasedBy() !== $user && $project->getOwner() !== $user) {
            throw $this->createAccessDeniedException('Seul l\'acheteur ou l\'organisateur peut annuler cet achat.');
        }

        if ($this->isCsrfTokenValid(
            'unmark_purchased' . (string) $giftIdea->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            $giftIdea->setPurchasedBy(null);
            $giftIdea->setPurchasePrice(null);
            $giftIdea->setPurchasedAt(null);
            $this->entityManager->flush();
            $this->addFlash('info', 'Achat annulé.');

            if ($request->headers->get('HX-Request')) {
                return new Response(null, 204, [
                    'HX-Redirect' => $this->generateUrl('app_project_show', [
                        'id' => $project->getId(),
                        'tab' => 'ideas',
                    ]),
                ]);
            }
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
    }

    #[Route('/idea/{id}/delete', name: 'app_gift_idea_delete', methods: ['DELETE'])]
    public function deleteGiftIdea(Request $request, GiftIdea $giftIdea): Response
    {
        $project = $this->requireIdeaProject($giftIdea);

        if (!$this->isGranted(GiftIdeaVoter::DELETE, $giftIdea)) {
            throw $this->createAccessDeniedException("Vous n'avez pas le droit de supprimer cette idée.");
        }

        $denied = $this->denyWhenDelivered($project, $request, 'ideas');
        if ($denied !== null) {
            return $denied;
        }

        $payloadToken = $request->getPayload()->getString('_token');
        $token = $payloadToken !== '' ? $payloadToken : $request->query->getString('_token');
        if ($this->isCsrfTokenValid('delete_idea' . (string) $giftIdea->getId(), $token)) {
            $this->entityManager->remove($giftIdea);
            $this->entityManager->flush();
            $this->addFlash('success', 'Idée supprimée.');

            if ($request->headers->get('HX-Request')) {
                return new Response('', Response::HTTP_OK);
            }
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
    }

    #[Route('/idea/{id}/status/{status}', name: 'app_gift_idea_status', methods: ['POST'])]
    public function changeStatus(Request $request, GiftIdea $giftIdea, string $status): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $project = $this->requireIdeaProject($giftIdea);

        if (!$this->isGranted(GiftProjectVoter::MANAGE, $project)) {
            throw $this->createAccessDeniedException("Seul l'organisateur peut changer le statut.");
        }

        $denied = $this->denyWhenDelivered($project, $request, 'ideas');
        if ($denied !== null) {
            return $denied;
        }

        $statusEnum = GiftIdeaStatus::tryFrom($status);
        if ($statusEnum === null) {
            throw $this->createNotFoundException('Statut invalide.');
        }

        if ($this->isCsrfTokenValid(
            'status_idea' . (string) $giftIdea->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            $giftIdea->setStatus($statusEnum->value);

            $activityType = match ($statusEnum) {
                GiftIdeaStatus::SELECTED => Activity::TYPE_IDEA_SELECTED,
                GiftIdeaStatus::DISCARDED => Activity::TYPE_IDEA_DISCARDED,
                GiftIdeaStatus::PROPOSED => null,
            };
            if ($activityType !== null) {
                $this->activityLogger->log($project, $activityType, $user, [
                    'idea_name' => $giftIdea->getName(),
                ]);
            }

            $this->entityManager->flush();
            $this->addFlash('success', 'Statut mis à jour.');
        }

        if ($request->headers->get('HX-Request')) {
            $sort = $request->query->getString('sort', 'status');
            $sortedGiftIdeas = $this->sortGiftIdeas($project, $sort);

            return $this->render('gift_project/_ideas.html.twig', [
                'project' => $project,
                'gift_ideas' => $sortedGiftIdeas,
                'current_sort' => $sort,
            ]);
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
    }

    #[Route('/idea/{id}/edit', name: 'app_gift_idea_edit', methods: ['GET', 'POST'])]
    public function editGiftIdea(Request $request, GiftIdea $giftIdea): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $project = $this->requireIdeaProject($giftIdea);

        if (!$this->isGranted(GiftIdeaVoter::EDIT, $giftIdea)) {
            throw $this->createAccessDeniedException("Vous n'avez pas le droit de modifier cette idée.");
        }

        $denied = $this->denyWhenDelivered($project, $request, 'ideas');
        if ($denied !== null) {
            return $denied;
        }

        if ($project->isLocked()) {
            throw $this->createAccessDeniedException('Impossible de modifier une idée dans un projet verrouillé.');
        }

        $form = $this->createForm(GiftIdeaType::class, $giftIdea);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'Idée cadeau modifiée !');

            if ($request->headers->get('HX-Request')) {
                return $this->render('gift_project/_idea_display.html.twig', [
                    'project' => $project,
                    'idea' => $giftIdea,
                    'user' => $user,
                ]);
            }

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
        }

        if ($request->headers->get('HX-Request')) {
            return $this->render('gift_project/_gift_idea_edit_form.html.twig', [
                'form' => $form,
                'project' => $project,
                'idea' => $giftIdea,
            ]);
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
    }

    #[Route('/idea/{id}/display', name: 'app_gift_idea_show_display', methods: ['GET'])]
    public function showGiftIdeaDisplay(GiftIdea $idea): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $project = $this->requireIdeaProject($idea);
        $this->denyUnlessCanViewProject($project, $user);

        return $this->render('gift_project/_idea_display.html.twig', [
            'idea' => $idea,
            'project' => $project,
            'user' => $user,
        ]);
    }

    #[Route('/create-from-wish/{contactId}/{wishId}', name: 'app_project_create_from_wish', methods: ['POST'])]
    public function createProjectFromWish(
        #[MapEntity(id: 'contactId')]
        User $recipient,
        #[MapEntity(id: 'wishId')]
        Wish $wish,
        WishlistShareRepository $wishlistShareRepository,
        Request $request,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if ($wish->getUser() !== $recipient) {
            throw $this->createAccessDeniedException('Ce souhait n\'appartient pas à ce contact.');
        }

        $share = $wishlistShareRepository->findOneBy([
            'owner' => $recipient,
            'recipient' => $user,
        ]);
        if ($recipient === $user || !$share) {
            throw $this->createAccessDeniedException("Vous n'avez pas accès à la liste de souhaits de ce contact.");
        }

        return $this->handleCreateProjectFromWish(
            $user,
            $wish,
            $recipient,
            $this->generateUrl('app_contact_view', ['id' => $recipient->getId()]),
            $request,
        );
    }

    #[Route(
        '/create-from-managed-wish/{managedPersonId}/{wishId}',
        name: 'app_project_create_from_managed_wish',
        methods: ['POST'],
    )]
    public function createProjectFromManagedWish(
        #[MapEntity(id: 'managedPersonId')]
        ManagedPerson $managedPerson,
        #[MapEntity(id: 'wishId')]
        Wish $wish,
        WishlistShareRepository $wishlistShareRepository,
        Request $request,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if ($wish->getManagedPerson() !== $managedPerson) {
            throw $this->createAccessDeniedException('Ce souhait n\'appartient pas à cette liste.');
        }

        $hasAccess =
            $managedPerson->isManagedBy($user)
            || $wishlistShareRepository->hasShareWithAnyManager($user, $managedPerson);
        if (!$hasAccess) {
            throw $this->createAccessDeniedException("Vous n'avez pas accès à la liste de souhaits de ce proche.");
        }

        return $this->handleCreateProjectFromWish(
            $user,
            $wish,
            $managedPerson,
            $this->generateUrl('app_contact_managed_view', ['id' => $managedPerson->getId()]),
            $request,
        );
    }

    private function handleCreateProjectFromWish(
        User $user,
        Wish $wish,
        User|ManagedPerson $recipient,
        string $fallbackRedirectUrl,
        Request $request,
    ): Response {
        $reservedBy = $wish->getReservedBy();
        if ($reservedBy !== null && $reservedBy !== $user || $wish->getActiveProject() !== null) {
            $this->addFlash('warning', 'Ce souhait est déjà réservé ou pris en charge dans un projet.');

            return $this->redirect($fallbackRedirectUrl);
        }

        if ($this->isCsrfTokenValid(
            'create_project_from_wish' . (string) $wish->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            if ($reservedBy === $user) {
                $wish->setReservedBy(null);
                $wish->setReservedAt(null);
            }

            $recipientDisplayName = $recipient->getDisplayName();
            $project = new GiftProject();
            $project->setOwner($user);
            if ($recipient instanceof ManagedPerson) {
                $project->setManagedRecipient($recipient);
            }
            if ($recipient instanceof User) {
                $project->setRecipient($recipient);
            }
            $project->setName("Cadeau pour {$recipientDisplayName} - {$wish->getName()}");
            $project->setDescription(
                "Projet cadeau créé à partir du souhait de {$recipientDisplayName}: '{$wish->getName()}'.",
            );
            $this->entityManager->persist($project);

            $giftIdea = GiftIdea::createFromWish($wish, $project, $user);
            $this->entityManager->persist($giftIdea);

            $this->activityLogger->log($project, Activity::TYPE_IDEA_ADDED, $user, [
                'idea_name' => $giftIdea->getName(),
            ]);

            $this->entityManager->flush();

            $this->addFlash(
                'success',
                "Projet cadeau créé pour {$recipientDisplayName} avec le souhait '{$wish->getName()}'.",
            );

            if ($request->headers->get('HX-Request')) {
                return new Response(null, 204, [
                    'HX-Redirect' => $this->generateUrl('app_project_show', ['id' => $project->getId()]),
                ]);
            }
        }

        return $this->redirect($fallbackRedirectUrl);
    }

    #[Route('/idea/{id}/vote', name: 'app_gift_idea_vote', methods: ['POST'])]
    public function toggleVote(GiftIdea $giftIdea, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $project = $this->requireIdeaProject($giftIdea);
        $this->denyUnlessCanViewProject($project, $user);

        if ($project->isLocked() || $project->getDeliveredAt() !== null) {
            return new Response(null, 204);
        }

        if ($this->isCsrfTokenValid(
            'vote_idea' . (string) $giftIdea->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            $existingVote = $this->entityManager
                ->getRepository(Vote::class)
                ->findOneBy(['giftIdea' => $giftIdea, 'user' => $user]);

            if ($existingVote !== null) {
                $this->entityManager->remove($existingVote);
            }
            if ($existingVote === null) {
                $vote = new Vote();
                $vote->setGiftIdea($giftIdea);
                $vote->setUser($user);
                $this->entityManager->persist($vote);
            }

            $this->entityManager->flush();
        }

        if ($request->headers->get('HX-Request')) {
            $sort = $request->query->getString('sort', 'status');
            $sortedGiftIdeas = $this->sortGiftIdeas($project, $sort);

            return $this->render('gift_project/_ideas.html.twig', [
                'project' => $project,
                'gift_ideas' => $sortedGiftIdeas,
                'current_sort' => $sort,
            ]);
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
    }

    #[Route('/idea/{id}/toggle-crowdfund', name: 'app_gift_idea_toggle_crowdfund', methods: ['POST'])]
    public function toggleCrowdfund(GiftIdea $giftIdea, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $project = $this->requireIdeaProject($giftIdea);
        $this->denyUnlessCanViewProject($project, $user);

        $denied = $this->denyWhenDelivered($project, $request, 'ideas');
        if ($denied !== null) {
            return $denied;
        }

        if ($project->isLocked()) {
            throw $this->createAccessDeniedException(
                'Impossible de modifier le mode cagnotte sur un projet verrouillé.',
            );
        }

        if ($this->isCsrfTokenValid(
            'toggle_crowdfund' . (string) $giftIdea->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            $giftIdea->setIsCrowdfunded(!$giftIdea->isCrowdfunded());
            $this->entityManager->flush();

            $msg = $giftIdea->isCrowdfunded()
                ? 'Mode cagnotte activé pour cette idée.'
                : 'Mode cagnotte désactivé pour cette idée.';
            $this->addFlash('info', $msg);
        }

        if ($request->headers->get('HX-Request')) {
            return $this->render('gift_project/_idea_display.html.twig', [
                'project' => $project,
                'idea' => $giftIdea,
                'user' => $user,
            ]);
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
    }

    #[Route('/idea/{id}/contribute', name: 'app_gift_idea_contribute', methods: ['POST'])]
    public function contribute(GiftIdea $giftIdea, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $project = $this->requireIdeaProject($giftIdea);
        $this->denyUnlessCanViewProject($project, $user);

        $denied = $this->denyWhenDelivered($project, $request, 'ideas');
        if ($denied !== null) {
            return $denied;
        }

        if ($giftIdea->getPurchasedBy() !== null) {
            $this->addFlash('warning', 'Cette idée a déjà été achetée : la cagnotte est clôturée.');

            return $this->renderIdeaDisplayOrRedirect($project, $giftIdea, $user, $request);
        }

        if ($this->isCsrfTokenValid(
            'contribute_idea' . (string) $giftIdea->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            $this->processContribution($giftIdea, $user, $project, $request);
        }

        return $this->renderIdeaDisplayOrRedirect($project, $giftIdea, $user, $request);
    }

    private function processContribution(GiftIdea $giftIdea, User $user, GiftProject $project, Request $request): void
    {
        $action = $request->getPayload()->getString('action');
        $rawAmount = trim($request->getPayload()->getString('amount'));
        $amount = is_numeric($rawAmount) ? (float) $rawAmount : 0.0;
        $existing = $giftIdea->getContributionForUser($user);

        if ($action === 'cancel' || $amount <= 0.0) {
            if ($existing !== null) {
                $giftIdea->removeContribution($existing);
                $this->entityManager->remove($existing);
                $this->entityManager->flush();
                $this->addFlash('info', "Votre participation pour « {$giftIdea->getName()} » a été retirée.");
            }
        }

        if ($action !== 'cancel' && $amount > 0.0) {
            $formattedAmount = number_format($amount, 2, '.', '');
            $contrib = $existing ?? new GiftIdeaContribution();
            if ($existing === null) {
                $contrib->setUser($user);
                $contrib->setGiftIdea($giftIdea);
                $giftIdea->addContribution($contrib);
                $this->entityManager->persist($contrib);
            }
            if ($existing !== null) {
                $contrib->setUpdatedAt(new \DateTimeImmutable());
            }
            $contrib->setAmount($formattedAmount);

            $this->activityLogger->log($project, Activity::TYPE_CROWDFUND_CONTRIBUTED, $user, [
                'idea_name' => $giftIdea->getName(),
                'amount' => $formattedAmount,
            ]);

            $this->entityManager->flush();
            $this->addFlash('success', "Votre participation de {$formattedAmount} € a été enregistrée !");
        }
    }

    private function renderIdeaDisplayOrRedirect(
        GiftProject $project,
        GiftIdea $giftIdea,
        User $user,
        Request $request,
    ): Response {
        if ($request->headers->get('HX-Request')) {
            return $this->render('gift_project/_idea_display.html.twig', [
                'project' => $project,
                'idea' => $giftIdea,
                'user' => $user,
            ]);
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
    }
}
