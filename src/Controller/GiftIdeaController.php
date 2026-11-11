<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Activity;
use App\Entity\GiftIdea;
use App\Entity\GiftProject;
use App\Entity\User;
use App\Entity\Vote;
use App\Entity\Wish;
use App\Enum\GiftIdeaStatus;
use App\Form\GiftIdeaType;
use App\Repository\WishlistShareRepository;
use App\Security\Voter\GiftIdeaVoter;
use App\Security\Voter\GiftProjectVoter;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// @mago-expect lint:cyclomatic-complexity,kan-defect
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

        if ($this->isCsrfTokenValid(
            'copy_wish' . (string) $wish->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            $giftIdea = new GiftIdea();
            $giftIdea->setName((string) $wish->getName());
            $giftIdea->setDescription($wish->getDescription());
            $giftIdea->setUrl($wish->getUrl());
            $giftIdea->setPrice($wish->getPrice());
            $giftIdea->setGiftProject($project);
            $giftIdea->setProposer($user);
            $giftIdea->setStatus(GiftIdea::STATUS_PROPOSED);
            $giftIdea->setFromWish($wish);

            $this->activityLogger->log($project, Activity::TYPE_IDEA_ADDED, $user, [
                'idea_name' => $giftIdea->getName(),
            ]);

            $this->entityManager->persist($giftIdea);
            $this->entityManager->flush();

            $this->addFlash('success', 'Souhait copié en idée cadeau !');

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

        if ($giftIdea->getPurchasedBy() !== null) {
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
            $giftIdea->setPurchasedBy($user);
            $giftIdea->setPurchasePrice($formattedPrice);
            $giftIdea->setPurchasedAt(new \DateTimeImmutable());
            $this->activityLogger->log($project, Activity::TYPE_IDEA_PURCHASED, $user, [
                'idea_name' => $giftIdea->getName(),
                'price' => $formattedPrice,
            ]);
            $this->entityManager->flush();
            $this->addFlash('success', 'Cadeau marqué comme acheté !');

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

        if ($this->isCsrfTokenValid(
            'delete_idea' . (string) $giftIdea->getId(),
            $request->query->getString('_token'),
        )) {
            $this->entityManager->remove($giftIdea);
            $this->entityManager->flush();

            if ($request->headers->get('HX-Request')) {
                return new Response('', Response::HTTP_OK);
            }

            $this->addFlash('success', 'Idée supprimée.');
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
                'comment_forms' => $this->buildCommentForms($sortedGiftIdeas),
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
                    'comment_forms' => $this->buildCommentForms([$giftIdea]),
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
            'comment_forms' => $this->buildCommentForms([$idea]),
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

        if ($this->isCsrfTokenValid(
            'create_project_from_wish' . (string) $wish->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            $project = new GiftProject();
            $project->setOwner($user);
            $project->setRecipient($recipient);
            $project->setName("Cadeau pour {$recipient->getDisplayName()} - {$wish->getName()}");
            $project->setDescription(
                "Projet cadeau créé à partir du souhait de {$recipient->getDisplayName()}: '{$wish->getName()}'.",
            );
            $this->entityManager->persist($project);

            $giftIdea = new GiftIdea();
            $giftIdea->setName((string) $wish->getName());
            $giftIdea->setDescription($wish->getDescription());
            $giftIdea->setUrl($wish->getUrl());
            $giftIdea->setPrice($wish->getPrice());
            $giftIdea->setGiftProject($project);
            $giftIdea->setProposer($user);
            $giftIdea->setStatus(GiftIdea::STATUS_PROPOSED);
            $giftIdea->setFromWish($wish);
            $this->entityManager->persist($giftIdea);

            $this->activityLogger->log($project, Activity::TYPE_IDEA_ADDED, $user, [
                'idea_name' => $giftIdea->getName(),
            ]);

            $this->entityManager->flush();

            $this->addFlash(
                'success',
                "Projet cadeau créé pour {$recipient->getDisplayName()} avec le souhait '{$wish->getName()}'.",
            );

            if ($request->headers->get('HX-Request')) {
                return new Response(null, 204, [
                    'HX-Redirect' => $this->generateUrl('app_project_show', ['id' => $project->getId()]),
                ]);
            }
        }

        return $this->redirectToRoute('app_contact_view', ['id' => $recipient->getId()]);
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
            $commentForms = $this->buildCommentForms($sortedGiftIdeas);

            return $this->render('gift_project/_ideas.html.twig', [
                'project' => $project,
                'gift_ideas' => $sortedGiftIdeas,
                'comment_forms' => $commentForms,
                'current_sort' => $sort,
            ]);
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
    }
}
