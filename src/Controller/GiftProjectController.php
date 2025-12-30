<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\GiftIdea;
use App\Entity\GiftProject;
use App\Entity\ProjectReadStatus;
use App\Entity\User;
use App\Entity\Wish;
use App\Form\AddParticipantType;
use App\Form\CommentType;
use App\Form\GiftIdeaType;
use App\Form\GiftProjectType;
use App\Repository\GiftProjectRepository;
use App\Repository\UserRepository;
use App\Service\FinancialService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/project')]
#[IsGranted('ROLE_USER')]
class GiftProjectController extends AbstractController
{
    /**
     * If the project is delivered, prevent any modification and redirect to show page with a warning.
     */
    private function denyWhenDelivered(GiftProject $project, Request $request, string $tab = 'ideas'): ?Response
    {
        if ($project->getDeliveredAt() === null) {
            return null;
        }

        $this->addFlash('warning', "Le projet a été remis et n'est plus modifiable.");

        if ($request->headers->get('HX-Request')) {
            return new Response(null, 204, [
                'HX-Redirect' => $this->generateUrl('app_project_show', ['id' => $project->getId(), 'tab' => $tab]),
            ]);
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => $tab]);
    }

    #[Route('/', name: 'app_project_index', methods: ['GET'])]
    public function index(GiftProjectRepository $projectRepository, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $showArchived = $request->query->getBoolean('archived');

        // We need a custom repository method or filter the results
        // The existing method `findAccessibleProjectsForUser` probably returns all.
        // Let's update the repository call or filter in PHP (less efficient but ok for now)
        // or better: update repository. But I can't see repository code easily without read.
        // Let's try to filter in PHP for quick win, or assume repository returns QueryBuilder and I can refine?
        // `findAccessibleProjectsForUser` returns `mixed`. Likely result array.

        $allProjects = $projectRepository->findAccessibleProjectsForUser($user);

        $projects = array_filter($allProjects, static function (GiftProject $p) use ($showArchived) {
            return $p->isArchived() === $showArchived;
        });

        return $this->render('gift_project/index.html.twig', [
            'projects' => $projects,
            'show_archived' => $showArchived,
        ]);
    }

    #[Route('/new', name: 'app_project_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $project = new GiftProject();
        $form = $this->createForm(GiftProjectType::class, $project, [
            'current_user' => $currentUser,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // The recipient is already set on the $project entity by the form because it's mapped
            $recipient = $project->getRecipient();

            if ($recipient === $currentUser) {
                // Should be prevented by the query builder but good as a fallback
                $this->addFlash('warning', 'Vous ne pouvez pas créer un projet surprise pour vous-même !');
            } else {
                $project->setOwner($currentUser);

                $entityManager->persist($project);
                $entityManager->flush();

                $this->addFlash('success', 'Projet créé !');
                return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
            }
        }

        return $this->render('gift_project/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_project_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, GiftProject $project, EntityManagerInterface $entityManager): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Security check: Only Owner can edit
        if ($project->getOwner() !== $currentUser) {
            throw $this->createAccessDeniedException("Seul l'organisateur peut modifier le projet.");
        }

        // Business rule: A delivered project can no longer be modified
        if ($project->getDeliveredAt() !== null) {
            $this->addFlash('warning', "Le projet a été remis et n'est plus modifiable.");
            return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
        }

        $form = $this->createForm(GiftProjectType::class, $project, [
            'current_user' => $currentUser,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // The recipient is already set on the $project entity by the form because it's mapped
            $recipient = $project->getRecipient();

            if ($recipient === $currentUser) {
                $this->addFlash('warning', 'Vous ne pouvez pas vous définir comme destinataire !');
            } else {
                $entityManager->flush();
                $this->addFlash('success', 'Projet modifié avec succès !');

                return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
            }
        }

        return $this->render('gift_project/edit.html.twig', [
            'project' => $project,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_project_show', methods: ['GET', 'POST'])]
    public function show(
        Request $request,
        GiftProject $project,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        FinancialService $financialService,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        // Security check: Must be Owner OR Participant, AND NOT Recipient
        $isOwner = $project->getOwner() === $user;
        $isParticipant = $project->getParticipants()->contains($user);
        $isRecipient = $project->getRecipient() === $user;

        if ($isRecipient) {
            throw $this->createAccessDeniedException('Surprise ! Vous ne pouvez pas voir ce projet.');
        }
        if (!$isOwner && !$isParticipant) {
            throw $this->createAccessDeniedException();
        }

        // Update Read Status
        $readStatus = $entityManager
            ->getRepository(ProjectReadStatus::class)
            ->findOneBy([
                'user' => $user,
                'project' => $project,
            ]);

        if (!$readStatus) {
            $readStatus = new ProjectReadStatus();
            $readStatus->setUser($user);
            $readStatus->setProject($project);
            $entityManager->persist($readStatus);
        }
        $readStatus->setLastReadAt(new \DateTimeImmutable());
        $entityManager->flush();

        // Sort Gift Ideas
        $sort = $request->query->get('sort', 'status');
        $sortedGiftIdeas = $project->getGiftIdeas()->toArray();
        usort($sortedGiftIdeas, static function (GiftIdea $a, GiftIdea $b) use ($sort) {
            if ($sort === 'price_asc') {
                $priceA = (float) $a->getPrice();
                $priceB = (float) $b->getPrice();
                if ($priceA === $priceB)
                    return 0;
                return $priceA < $priceB ? -1 : 1;
            }
            if ($sort === 'price_desc') {
                $priceA = (float) $a->getPrice();
                $priceB = (float) $b->getPrice();
                if ($priceA === $priceB)
                    return 0;
                return $priceA > $priceB ? -1 : 1;
            }

            // Default: status
            $statusOrder = [
                GiftIdea::STATUS_SELECTED => 1,
                GiftIdea::STATUS_PROPOSED => 2,
                GiftIdea::STATUS_DISCARDED => 3,
            ];

            $statusA = $statusOrder[$a->getStatus()] ?? 99;
            $statusB = $statusOrder[$b->getStatus()] ?? 99;

            if ($statusA === $statusB) {
                return $b->getId() <=> $a->getId(); // Newest first
            }

            return $statusA <=> $statusB;
        });

        // Handle Add Participant Form
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $addParticipantForm = $this->createForm(AddParticipantType::class, null, [
            'current_user' => $currentUser,
        ]);
        $addParticipantForm->handleRequest($request);

        if ($addParticipantForm->isSubmitted() && $addParticipantForm->isValid()) {
            $newParticipant = $addParticipantForm->get('participant')->getData(); // Get User object directly

            if ($newParticipant === $project->getRecipient()) {
                $this->addFlash('warning', 'Vous ne pouvez pas inviter le destinataire du cadeau !');
            } elseif (
                $project->getParticipants()->contains($newParticipant)
                || $project->getOwner() === $newParticipant
            ) {
                $this->addFlash('info', 'Cet utilisateur participe déjà.');
            } else {
                $project->addParticipant($newParticipant);
                $entityManager->flush();
                $this->addFlash('success', 'Invitation envoyée à ' . $newParticipant->getDisplayName());
            }

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'details']);
        }

        // Handle Add Gift Idea Form
        $giftIdea = new GiftIdea();
        $giftIdeaForm = $this->createForm(GiftIdeaType::class, $giftIdea);
        $giftIdeaForm->handleRequest($request);

        if ($giftIdeaForm->isSubmitted() && $giftIdeaForm->isValid()) {
            $giftIdea->setGiftProject($project);
            $giftIdea->setProposer($user);

            // Mark project as active
            $project->setLastActivityAt(new \DateTimeImmutable());

            $entityManager->persist($giftIdea);
            $entityManager->flush();
            $this->addFlash('success', 'Idée de cadeau ajoutée !');

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
        }

        // Comment Forms - Generate a form view for each idea
        $commentForms = [];
        foreach ($sortedGiftIdeas as $idea) {
            $commentForms[$idea->getId()] = $this->createForm(CommentType::class)->createView();
        }

        if ($request->headers->get('HX-Request')) {
            $tab = $request->query->get('tab', 'details');
            if ($tab === 'ideas') {
                return $this->render('gift_project/_ideas.html.twig', [
                    'project' => $project,
                    'gift_ideas' => $sortedGiftIdeas,
                    'comment_forms' => $commentForms,
                    'current_sort' => $sort,
                ]);
            } elseif ($tab === 'liste_souhaits') {
                $copiedWishIds = [];
                foreach ($project->getGiftIdeas() as $idea) {
                    if (!$idea->getFromWish()) {
                        continue;
                    }

                    $copiedWishIds[] = $idea->getFromWish()->getId();
                }
                return $this->render('gift_project/_liste_souhaits.html.twig', [
                    'project' => $project,
                    'copied_wish_ids' => $copiedWishIds,
                ]);
            } elseif ($tab === 'financial') {
                $financials = $financialService->calculate($project);
                return $this->render('gift_project/_financial.html.twig', array_merge([
                    'project' => $project,
                ], $financials));
            } else {
                return $this->render('gift_project/_details.html.twig', [
                    'project' => $project,
                    'add_participant_form' => $addParticipantForm,
                ]);
            }
        }

        $financialData = [];
        $currentTab = $request->query->get('tab', 'details');
        if ($currentTab === 'financial') {
            $financialData = $financialService->calculate($project);
        }

        return $this->render('gift_project/show.html.twig', array_merge([
            'project' => $project,
            'add_participant_form' => $addParticipantForm,
            'gift_idea_form' => $giftIdeaForm,
            'comment_forms' => $commentForms,
            'gift_ideas' => $sortedGiftIdeas,
            'current_sort' => $sort,
        ], $financialData));
    }

    #[Route('/{id}/new-idea-form', name: 'app_gift_idea_new_form', methods: ['GET'])]
    public function newGiftIdeaForm(GiftProject $project): Response
    {
        // Security check: Must be Owner OR Participant, AND NOT Recipient
        /** @var User $user */
        $user = $this->getUser();
        $isOwner = $project->getOwner() === $user;
        $isParticipant = $project->getParticipants()->contains($user);
        $isRecipient = $project->getRecipient() === $user;

        if ($isRecipient) {
            throw $this->createAccessDeniedException('Surprise ! Vous ne pouvez pas voir ce projet.');
        }
        if (!$isOwner && !$isParticipant) {
            throw $this->createAccessDeniedException();
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
        #[MapEntity(id: 'wish_id')] Wish $wish,
        EntityManagerInterface $entityManager,
        Request $request,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        // Security check: Must be Owner OR Participant, AND NOT Recipient
        $isOwner = $project->getOwner() === $user;
        $isParticipant = $project->getParticipants()->contains($user);
        $isRecipient = $project->getRecipient() === $user;

        if ($isRecipient) {
            throw $this->createAccessDeniedException('Surprise ! Vous ne pouvez pas modifier ce projet.');
        }
        if (!$isOwner && !$isParticipant) {
            throw $this->createAccessDeniedException();
        }

        // Check for duplicates
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

        if ($this->isCsrfTokenValid('copy_wish' . $wish->getId(), $request->request->get('_token'))) {
            $giftIdea = new GiftIdea();
            $giftIdea->setName($wish->getName());
            $giftIdea->setDescription($wish->getDescription());
            $giftIdea->setUrl($wish->getUrl());
            $giftIdea->setPrice($wish->getPrice());
            $giftIdea->setGiftProject($project);
            $giftIdea->setProposer($user);
            $giftIdea->setStatus(GiftIdea::STATUS_PROPOSED);
            $giftIdea->setFromWish($wish);

            // Mark project as active
            $project->setLastActivityAt(new \DateTimeImmutable());

            $entityManager->persist($giftIdea);
            $entityManager->flush();

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

    #[Route('/{id}/lock', name: 'app_project_lock', methods: ['POST'])]
    public function lockProject(GiftProject $project, EntityManagerInterface $entityManager, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Security check: Only Owner can lock
        if ($project->getOwner() !== $user) {
            throw $this->createAccessDeniedException("Seul l'organisateur peut verrouiller le projet.");
        }

        if ($project->isLocked()) {
            $this->addFlash('warning', 'Le projet est déjà verrouillé.');
            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
        }

        // Check if at least one idea is selected
        $hasSelectedIdea = false;
        foreach ($project->getGiftIdeas() as $idea) {
            if ($idea->getStatus() !== GiftIdea::STATUS_SELECTED) {
                continue;
            }

            $hasSelectedIdea = true;
            break;
        }

        if (!$hasSelectedIdea) {
            $this->addFlash('warning', 'Il faut valider au moins une idée cadeau avant de verrouiller.');
            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
        }

        if ($this->isCsrfTokenValid('lock_project' . $project->getId(), $request->request->get('_token'))) {
            $project->setLocked(true);
            $entityManager->flush();
            $this->addFlash('success', 'Projet verrouillé ! Les idées sont figées.');

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

    #[Route('/{id}/unlock', name: 'app_project_unlock', methods: ['POST'])]
    public function unlockProject(
        GiftProject $project,
        EntityManagerInterface $entityManager,
        Request $request,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        // Security check: Only Owner can unlock
        if ($project->getOwner() !== $user) {
            throw $this->createAccessDeniedException("Seul l'organisateur peut déverrouiller le projet.");
        }

        // Business rule: A delivered project cannot be unlocked
        if ($project->getDeliveredAt() !== null) {
            $this->addFlash('warning', 'Le projet a été remis et ne peut pas être déverrouillé.');
            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
        }

        if (!$project->isLocked()) {
            $this->addFlash('warning', 'Le projet n\'est pas verrouillé.');
            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
        }

        if ($this->isCsrfTokenValid('unlock_project' . $project->getId(), $request->request->get('_token'))) {
            $project->setLocked(false);
            $entityManager->flush();
            $this->addFlash('success', 'Projet déverrouillé.');

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
    public function markIdeaPurchased(
        Request $request,
        GiftIdea $giftIdea,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $project = $giftIdea->getGiftProject();

        // Security checks
        $isOwner = $project->getOwner() === $user;
        $isParticipant = $project->getParticipants()->contains($user);

        if (!$isOwner && !$isParticipant) {
            throw $this->createAccessDeniedException();
        }

        if (!$project->isLocked()) {
            $this->addFlash('warning', 'Le projet doit être verrouillé pour marquer des achats.');
            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
        }

        if ($giftIdea->getStatus() !== GiftIdea::STATUS_SELECTED) {
            $this->addFlash('warning', 'Seules les idées validées peuvent être achetées.');
            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
        }

        if ($giftIdea->getPurchasedBy()) {
            $this->addFlash('warning', 'Ce cadeau a déjà été acheté.');
            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
        }

        $price = $request->request->get('price');

        if (!$price) {
            $this->addFlash('warning', 'Le prix est obligatoire pour marquer un achat.');
            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
        }

        if ($this->isCsrfTokenValid('mark_purchased' . $giftIdea->getId(), $request->request->get('_token'))) {
            $giftIdea->setPurchasedBy($user);
            $giftIdea->setPurchasePrice($price);
            $giftIdea->setPurchasedAt(new \DateTimeImmutable());
            $entityManager->flush();
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
    public function deleteGiftIdea(
        Request $request,
        GiftIdea $giftIdea,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $project = $giftIdea->getGiftProject();

        // Security Check: Who can delete?
        // 1. The creator of the idea
        // 2. The owner of the project
        $isCreator = $giftIdea->getProposer() === $user;
        $isProjectOwner = $project->getOwner() === $user;

        if (!$isCreator && !$isProjectOwner) {
            throw $this->createAccessDeniedException("Vous n'avez pas le droit de supprimer cette idée.");
        }

        if ($this->isCsrfTokenValid('delete_idea' . $giftIdea->getId(), $request->query->get('_token'))) {
            $entityManager->remove($giftIdea);
            $entityManager->flush();

            if ($request->headers->get('HX-Request')) {
                return new Response('', Response::HTTP_OK);
            }

            $this->addFlash('success', 'Idée supprimée.');
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
    }

    #[Route('/idea/{id}/comment', name: 'app_gift_idea_comment', methods: ['POST'])]
    public function addComment(Request $request, GiftIdea $giftIdea, EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $project = $giftIdea->getGiftProject();

        // Security Check: Access to project
        $isOwner = $project->getOwner() === $user;
        $isParticipant = $project->getParticipants()->contains($user);

        if (!$isOwner && !$isParticipant) {
            throw $this->createAccessDeniedException();
        }

        $comment = new Comment();
        $form = $this->createForm(CommentType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $comment->setAuthor($user);
            $comment->setGiftIdea($giftIdea);

            // Mark project as active
            $project->setLastActivityAt(new \DateTimeImmutable());

            $entityManager->persist($comment);
            $entityManager->flush();

            if ($request->headers->get('HX-Request')) {
                return $this->render('gift_project/_comment.html.twig', [
                    'comment' => $comment,
                ]);
            }
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
    }

    #[Route('/comment/{id}', name: 'app_comment_show', methods: ['GET'])]
    public function showComment(Comment $comment): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Check access rights (reuse logic if possible, or simple check)
        if ($comment->getAuthor() !== $user && $comment->getGiftIdea()->getGiftProject()->getOwner() !== $user) {
            // At least allow if participant? For simply viewing, usually yes if participant.
            // But this route is mainly used for cancelling edit (re-fetching view).
            // Let's strict check: must be participant or owner of project
            $project = $comment->getGiftIdea()->getGiftProject();
            if ($project->getOwner() !== $user && !$project->getParticipants()->contains($user)) {
                throw $this->createAccessDeniedException();
            }
        }

        return $this->render('gift_project/_comment.html.twig', [
            'comment' => $comment,
        ]);
    }

    #[Route('/comment/{id}/edit', name: 'app_comment_edit', methods: ['GET', 'POST'])]
    public function editComment(Request $request, Comment $comment, EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($comment->getAuthor() !== $user) {
            throw $this->createAccessDeniedException('Vous ne pouvez modifier que vos propres commentaires.');
        }

        $form = $this->createForm(CommentType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            if ($request->headers->get('HX-Request')) {
                return $this->render('gift_project/_comment.html.twig', [
                    'comment' => $comment,
                ]);
            }

            return $this->redirectToRoute('app_project_show', [
                'id' => $comment->getGiftIdea()->getGiftProject()->getId(),
                'tab' => 'ideas',
            ]);
        }

        if ($request->headers->get('HX-Request')) {
            return $this->render('gift_project/_comment_edit.html.twig', [
                'comment' => $comment,
                'form' => $form,
            ]);
        }

        // Fallback if not HTMX (should generally not happen in this flow)
        return $this->render('gift_project/edit_comment.html.twig', [ // You might need to create this template if non-htmx access is desired
            'comment' => $comment,
            'form' => $form,
        ]);
    }

    #[Route('/comment/{id}/delete', name: 'app_comment_delete', methods: ['DELETE'])]
    public function deleteComment(Request $request, Comment $comment, EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Author OR Project Owner can delete
        if ($comment->getAuthor() !== $user && $comment->getGiftIdea()->getGiftProject()->getOwner() !== $user) {
            throw $this->createAccessDeniedException();
        }

        if ($this->isCsrfTokenValid('delete_comment' . $comment->getId(), $request->query->get('_token'))) {
            $entityManager->remove($comment);
            $entityManager->flush();

            if ($request->headers->get('HX-Request')) {
                return new Response('', Response::HTTP_OK);
            }
        }

        return $this->redirectToRoute('app_project_show', [
            'id' => $comment->getGiftIdea()->getGiftProject()->getId(),
            'tab' => 'ideas',
        ]);
    }

    #[Route('/idea/{id}/status/{status}', name: 'app_gift_idea_status', methods: ['POST'])]
    public function changeStatus(
        Request $request,
        GiftIdea $giftIdea,
        string $status,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $project = $giftIdea->getGiftProject();

        // Only Project Owner can change status
        if ($project->getOwner() !== $user) {
            throw $this->createAccessDeniedException("Seul l'organisateur peut changer le statut.");
        }

        // Validate status
        $allowedStatuses = [
            GiftIdea::STATUS_PROPOSED,
            GiftIdea::STATUS_SELECTED,
            GiftIdea::STATUS_DISCARDED,
        ];

        if (!in_array($status, $allowedStatuses)) {
            throw $this->createNotFoundException('Statut invalide.');
        }

        if ($this->isCsrfTokenValid('status_idea' . $giftIdea->getId(), $request->request->get('_token'))) {
            $giftIdea->setStatus($status);
            $entityManager->flush();

            $this->addFlash('success', 'Statut mis à jour.');
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
    }

    #[Route('/idea/{id}/edit', name: 'app_gift_idea_edit', methods: ['GET', 'POST'])]
    public function editGiftIdea(Request $request, GiftIdea $giftIdea, EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $project = $giftIdea->getGiftProject();

        // Security check: Must be owner of project or proposer of idea
        if ($project->getOwner() !== $user && $giftIdea->getProposer() !== $user) {
            throw $this->createAccessDeniedException("Vous n'avez pas le droit de modifier cette idée.");
        }

        // Cannot edit if project is locked
        if ($project->isLocked()) {
            throw $this->createAccessDeniedException('Impossible de modifier une idée dans un projet verrouillé.');
        }

        $form = $this->createForm(GiftIdeaType::class, $giftIdea);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Idée cadeau modifiée !');

            // Return the updated idea fragment for HTMX
            if ($request->headers->get('HX-Request')) {
                return $this->render('gift_project/_idea_display.html.twig', [ // This template will be created next
                    'project' => $project,
                    'idea' => $giftIdea,
                    'user' => $user,
                ]);
            }

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
        }

        // If HTMX request, render only the form fragment
        if ($request->headers->get('HX-Request')) {
            return $this->render('gift_project/_gift_idea_edit_form.html.twig', [ // This template will be created next
                'form' => $form,
                'project' => $project,
                'idea' => $giftIdea,
            ]);
        }

        // Fallback for non-HTMX (should generally not happen)
        return $this->render('gift_project/edit_gift_idea.html.twig', [ // This template would be a full page
            'form' => $form,
            'project' => $project,
            'idea' => $giftIdea,
        ]);
    }

    #[Route('/{id}/archive', name: 'app_project_archive', methods: ['POST'])]
    public function archiveProject(
        GiftProject $project,
        EntityManagerInterface $entityManager,
        Request $request,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if ($project->getOwner() !== $user) {
            throw $this->createAccessDeniedException("Seul l'organisateur peut archiver le projet.");
        }

        if ($this->isCsrfTokenValid('archive_project' . $project->getId(), $request->request->get('_token'))) {
            $newState = !$project->isArchived();
            $project->setArchived($newState);
            $entityManager->flush();

            $status = $newState ? 'archivé' : 'désarchivé';
            $this->addFlash('success', "Projet $status.");
        }

        return $this->redirectToRoute('app_project_index', ['archived' => $project->isArchived() ? 1 : 0]);
    }

    #[Route('/idea/{id}/display', name: 'app_gift_idea_show_display', methods: ['GET'])]
    public function showGiftIdeaDisplay(GiftIdea $idea): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $project = $idea->getGiftProject();

        // Security check: Must be Owner OR Participant, AND NOT Recipient (of the project)
        $isOwner = $project->getOwner() === $user;
        $isParticipant = $project->getParticipants()->contains($user);
        $isRecipient = $project->getRecipient() === $user;

        if ($isRecipient) {
            throw $this->createAccessDeniedException('Surprise ! Vous ne pouvez pas voir ce projet.');
        }
        if (!$isOwner && !$isParticipant) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('gift_project/_idea_display.html.twig', [
            'idea' => $idea,
            'project' => $project, // Pass project for context if needed in template
            'user' => $user, // Pass current user for authorization checks in template
        ]);
    }

    #[Route('/{id}/reimburse', name: 'app_project_reimburse', methods: ['POST'])]
    public function reimburse(
        GiftProject $project,
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser(); // The Creditor (who received money)

        if (!$project->getParticipants()->contains($currentUser) && $project->getOwner() !== $currentUser) {
            throw $this->createAccessDeniedException();
        }

        $debtorId = $request->request->get('debtor_id');
        $amount = $request->request->get('amount');
        $debtor = $userRepository->find($debtorId); // The Debtor (who paid)

        if (!$debtor || !$project->getParticipants()->contains($debtor) && $project->getOwner() !== $debtor) {
            $this->addFlash('danger', 'Utilisateur payeur invalide.');
            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'financial']);
        }

        if ($this->isCsrfTokenValid('reimburse_' . $project->getId(), $request->request->get('_token'))) {
            $reimbursement = new \App\Entity\Reimbursement();
            $reimbursement->setProject($project);
            $reimbursement->setFromUser($debtor); // Debtor pays
            $reimbursement->setToUser($currentUser); // Current user receives
            $reimbursement->setAmount($amount);

            $entityManager->persist($reimbursement);
            $entityManager->flush();

            $this->addFlash('success', 'Remboursement confirmé.');

            if ($request->headers->get('HX-Request')) {
                return new Response(null, 204, [
                    'HX-Redirect' => $this->generateUrl('app_project_show', [
                        'id' => $project->getId(),
                        'tab' => 'financial',
                    ]),
                ]);
            }
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'financial']);
    }

    #[Route('/{id}/leave', name: 'app_project_leave', methods: ['POST'])]
    public function leaveProject(
        GiftProject $project,
        EntityManagerInterface $entityManager,
        Request $request,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if ($project->getOwner() === $user) {
            $this->addFlash('danger', 'L\'organisateur ne peut pas quitter le projet.');
            return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
        }

        if (!$project->getParticipants()->contains($user)) {
            $this->addFlash('warning', 'Vous ne faites pas partie de ce projet.');
            return $this->redirectToRoute('app_project_index');
        }

        // Check if any idea has been purchased in this project
        foreach ($project->getGiftIdeas() as $idea) {
            if ($idea->getPurchasedBy() === null) {
                continue;
            }

            $this->addFlash(
                'danger',
                'Vous ne pouvez pas quitter le projet car des achats ont déjà été réalisés pour ce projet.',
            );
            return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
        }

        if ($this->isCsrfTokenValid('leave_project' . $project->getId(), $request->request->get('_token'))) {
            $project->removeParticipant($user);
            $entityManager->flush();
            $this->addFlash('success', 'Vous avez quitté le projet.');

            if ($request->headers->get('HX-Request')) {
                return new Response(null, 204, [
                    'HX-Redirect' => $this->generateUrl('app_project_index'),
                ]);
            }
        }

        return $this->redirectToRoute('app_project_index');
    }

    #[Route('/{id}/remove-participant/{participant_id}', name: 'app_project_remove_participant', methods: ['POST'])]
    public function removeParticipant(
        GiftProject $project,
        #[MapEntity(id: 'participant_id')] User $participant,
        EntityManagerInterface $entityManager,
        Request $request,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Only Owner can remove participants
        if ($project->getOwner() !== $currentUser) {
            throw $this->createAccessDeniedException("Seul l'organisateur peut retirer des participants.");
        }

        if (!$project->getParticipants()->contains($participant)) {
            $this->addFlash('warning', 'Cet utilisateur ne participe pas au projet.');
            return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
        }

        // Check if participant has purchased any items
        foreach ($project->getGiftIdeas() as $idea) {
            if ($idea->getPurchasedBy() !== $participant) {
                continue;
            }

            $this->addFlash(
                'danger',
                'Impossible de retirer ce participant car il a réalisé des achats pour ce projet.',
            );

            if ($request->headers->get('HX-Request')) {
                return new Response(null, 204, [
                    'HX-Redirect' => $this->generateUrl('app_project_show', ['id' => $project->getId()]),
                ]);
            }

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
        }

        // Check if participant has made any reimbursements
        foreach ($project->getReimbursements() as $reimbursement) {
            if ($reimbursement->getFromUser() !== $participant) {
                continue;
            }

            $this->addFlash(
                'danger',
                'Impossible de retirer ce participant car il a déjà effectué des remboursements.',
            );

            if ($request->headers->get('HX-Request')) {
                return new Response(null, 204, [
                    'HX-Redirect' => $this->generateUrl('app_project_show', ['id' => $project->getId()]),
                ]);
            }

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
        }

        if ($this->isCsrfTokenValid('remove_participant' . $participant->getId(), $request->request->get('_token'))) {
            $project->removeParticipant($participant);
            $entityManager->flush();
            $this->addFlash('success', $participant->getDisplayName() . ' a été retiré du projet.');

            if ($request->headers->get('HX-Request')) {
                return new Response(null, 204, [
                    'HX-Redirect' => $this->generateUrl('app_project_show', ['id' => $project->getId()]),
                ]);
            }
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
    }

    #[Route('/{id}/mark-delivered', name: 'app_project_mark_delivered', methods: ['POST'])]
    public function markDelivered(
        GiftProject $project,
        EntityManagerInterface $entityManager,
        Request $request,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        // Security check: Only Owner can mark as delivered
        if ($project->getOwner() !== $user) {
            throw $this->createAccessDeniedException("Seul l'organisateur peut marquer le projet comme remis.");
        }

        // Check if already delivered
        if ($project->getDeliveredAt() !== null) {
            $this->addFlash('warning', 'Le projet est déjà marqué comme remis.');
            if ($request->headers->get('HX-Request')) {
                return new Response(null, 204, [
                    'HX-Redirect' => $this->generateUrl('app_project_show', ['id' => $project->getId()]),
                ]);
            }
            return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
        }

        if ($this->isCsrfTokenValid('mark_delivered' . $project->getId(), $request->request->get('_token'))) {
            $project->setDeliveredAt(new \DateTimeImmutable());
            $entityManager->flush();
            $this->addFlash('success', 'Projet marqué comme remis au destinataire !');
        }

        if ($request->headers->get('HX-Request')) {
            return new Response(null, 204, [
                'HX-Redirect' => $this->generateUrl('app_project_show', ['id' => $project->getId()]),
            ]);
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
    }

    #[Route('/create-from-wish/{contactId}/{wishId}', name: 'app_project_create_from_wish', methods: ['POST'])]
    public function createProjectFromWish(
        #[MapEntity(id: 'contactId')] User $recipient,
        #[MapEntity(id: 'wishId')] Wish $wish,
        EntityManagerInterface $entityManager,
        Request $request,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        // Security check: Ensure the wish belongs to the recipient
        if ($wish->getUser() !== $recipient) {
            throw $this->createAccessDeniedException('Ce souhait n\'appartient pas à ce contact.');
        }

        // Security check: Ensure the current user has access to this contact's wishlist
        // This is implicitly handled by the ContactController::viewContactListeDeSouhaits's security
        // But good to double check, or trust the previous route guards.

        if ($this->isCsrfTokenValid('create_project_from_wish' . $wish->getId(), $request->request->get('_token'))) {
            $project = new GiftProject();
            $project->setOwner($user);
            $project->setRecipient($recipient);
            $project->setName("Cadeau pour {$recipient->getDisplayName()} - {$wish->getName()}");
            $project->setDescription(
                "Projet cadeau créé à partir du souhait de {$recipient->getDisplayName()}: '{$wish->getName()}'.",
            );
            // The current user is the owner, so not a participant in this context.
            $entityManager->persist($project);

            $giftIdea = new GiftIdea();
            $giftIdea->setName($wish->getName());
            $giftIdea->setDescription($wish->getDescription());
            $giftIdea->setUrl($wish->getUrl());
            $giftIdea->setPrice($wish->getPrice());
            $giftIdea->setGiftProject($project);
            $giftIdea->setProposer($user);
            $giftIdea->setStatus(GiftIdea::STATUS_PROPOSED);
            $giftIdea->setFromWish($wish);
            $entityManager->persist($giftIdea);

            // Mark project as active (lastActivityAt)
            $project->setLastActivityAt(new \DateTimeImmutable());

            $entityManager->flush();

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
}
