<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Activity;
use App\Entity\GiftIdea;
use App\Entity\GiftProject;
use App\Entity\Reimbursement;
use App\Entity\User;
use App\Form\AddParticipantType;
use App\Form\CommentType;
use App\Form\GiftIdeaType;
use App\Form\GiftProjectType;
use App\Repository\ActivityRepository;
use App\Repository\GiftProjectRepository;
use App\Repository\ProjectReadStatusRepository;
use App\Repository\UserRepository;
use App\Security\Voter\GiftProjectVoter;
use App\Service\ActivityLogger;
use App\Service\FinancialService;
use App\Service\ProjectMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// @mago-expect lint:cyclomatic-complexity,kan-defect,too-many-methods
#[Route('/project')]
#[IsGranted('ROLE_USER')]
final class GiftProjectController extends AbstractProjectController
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly FinancialService $financialService,
        private readonly ActivityRepository $activityRepository,
        private readonly ProjectMailer $projectMailer,
    ) {}

    #[Route('/', name: 'app_project_index', methods: ['GET'])]
    public function index(GiftProjectRepository $projectRepository, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $showArchived = $request->query->getBoolean('archived');

        $projects = $projectRepository->findAccessibleProjectsForUser($user, $showArchived);
        $unreadCounts = $this->activityRepository->countUnreadPerProjectForUser($user);

        return $this->render('gift_project/index.html.twig', [
            'projects' => $projects,
            'show_archived' => $showArchived,
            'unread_counts' => $unreadCounts,
        ]);
    }

    #[Route('/new', name: 'app_project_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $project = new GiftProject();
        $form = $this->createForm(GiftProjectType::class, $project, [
            'current_user' => $currentUser,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($project->getRecipient() === $currentUser) {
                $this->addFlash('warning', 'Vous ne pouvez pas créer un projet surprise pour vous-même !');

                return $this->render('gift_project/new.html.twig', [
                    'form' => $form,
                ]);
            }

            $project->setOwner($currentUser);
            $this->entityManager->persist($project);
            $this->entityManager->flush();

            $this->addFlash('success', 'Projet créé !');

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
        }

        return $this->render('gift_project/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_project_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, GiftProject $project): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        if (!$this->isGranted(GiftProjectVoter::MANAGE, $project)) {
            throw $this->createAccessDeniedException("Seul l'organisateur peut modifier le projet.");
        }

        $denied = $this->denyWhenDelivered($project, $request, 'details');
        if ($denied !== null) {
            return $denied;
        }

        $form = $this->createForm(GiftProjectType::class, $project, [
            'current_user' => $currentUser,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($project->getRecipient() === $currentUser) {
                $this->addFlash('warning', 'Vous ne pouvez pas vous définir comme destinataire !');

                return $this->render('gift_project/edit.html.twig', [
                    'project' => $project,
                    'form' => $form,
                ]);
            }

            $this->entityManager->flush();
            $this->addFlash('success', 'Projet modifié avec succès !');

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
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
        ProjectReadStatusRepository $readStatusRepository,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $this->denyUnlessCanViewProject($project, $user);
        $readStatusRepository->markAsRead($user, $project);
        $this->entityManager->flush();

        $addParticipantForm = $this->createForm(AddParticipantType::class, null, [
            'current_user' => $user,
        ]);
        $addParticipantForm->handleRequest($request);

        if ($addParticipantForm->isSubmitted() && $addParticipantForm->isValid()) {
            $denied = $this->denyWhenDelivered($project, $request, 'details');
            if ($denied !== null) {
                return $denied;
            }

            /** @var User|null $newParticipant */
            $newParticipant = $addParticipantForm->get('participant')->getData();
            if ($newParticipant instanceof User) {
                $this->handleAddParticipant($project, $user, $newParticipant);
            }

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'details']);
        }

        $giftIdea = new GiftIdea();
        $giftIdeaForm = $this->createForm(GiftIdeaType::class, $giftIdea);
        $giftIdeaForm->handleRequest($request);

        if ($giftIdeaForm->isSubmitted() && $giftIdeaForm->isValid()) {
            $denied = $this->denyWhenDelivered($project, $request, 'ideas');
            if ($denied !== null) {
                return $denied;
            }

            $giftIdea->setGiftProject($project);
            $giftIdea->setProposer($user);
            $this->activityLogger->log($project, Activity::TYPE_IDEA_ADDED, $user, [
                'idea_name' => $giftIdea->getName(),
            ]);

            $this->entityManager->persist($giftIdea);
            $this->entityManager->flush();
            $this->addFlash('success', 'Idée de cadeau ajoutée !');

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
        }

        $sort = $request->query->getString('sort', 'status');
        $currentTab = $request->query->getString('tab', 'details');

        if ($request->headers->get('HX-Request')) {
            return $this->renderHtmxTab($currentTab, $project, $sort, $addParticipantForm);
        }

        $sortedGiftIdeas = $currentTab === 'ideas' ? $this->sortGiftIdeas($project, $sort) : [];
        $financialData = $currentTab === 'financial' ? $this->financialService->calculate($project) : [];

        return $this->render('gift_project/show.html.twig', array_merge([
            'project' => $project,
            'add_participant_form' => $addParticipantForm,
            'gift_idea_form' => $giftIdeaForm,
            'comment_form' => $currentTab === 'discussion' ? $this->createForm(CommentType::class)->createView() : null,
            'gift_ideas' => $sortedGiftIdeas,
            'current_sort' => $sort,
            'copied_wish_ids' => $currentTab === 'liste_souhaits' ? $this->getCopiedWishIds($project) : [],
            'activities' => $currentTab === 'activite'
                ? $this->activityRepository->findRecentForProject($project, 30)
                : [],
        ], $financialData));
    }

    #[Route('/{id}/lock', name: 'app_project_lock', methods: ['POST'])]
    public function lockProject(GiftProject $project, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isGranted(GiftProjectVoter::MANAGE, $project)) {
            throw $this->createAccessDeniedException("Seul l'organisateur peut verrouiller le projet.");
        }

        $denied = $this->denyWhenDelivered($project, $request, 'ideas');
        if ($denied !== null) {
            return $denied;
        }

        if ($project->isLocked()) {
            $this->addFlash('warning', 'Le projet est déjà verrouillé.');

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
        }

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

        if (!$this->isCsrfTokenValid(
            'lock_project' . (string) $project->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            $this->addFlash('danger', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
        }

        $project->setLocked(true);
        $this->activityLogger->log($project, Activity::TYPE_PROJECT_LOCKED, $user);
        $this->entityManager->flush();
        $this->addFlash('success', 'Projet verrouillé ! Les idées sont figées.');

        if ($request->headers->get('HX-Request')) {
            return new Response(null, 204, [
                'HX-Redirect' => $this->generateUrl('app_project_show', [
                    'id' => $project->getId(),
                    'tab' => 'ideas',
                ]),
            ]);
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
    }

    #[Route('/{id}/unlock', name: 'app_project_unlock', methods: ['POST'])]
    public function unlockProject(GiftProject $project, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isGranted(GiftProjectVoter::MANAGE, $project)) {
            throw $this->createAccessDeniedException("Seul l'organisateur peut déverrouiller le projet.");
        }

        $denied = $this->denyWhenDelivered($project, $request, 'ideas');
        if ($denied !== null) {
            return $denied;
        }

        if (!$project->isLocked()) {
            $this->addFlash('warning', 'Le projet n\'est pas verrouillé.');

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
        }

        if (!$this->isCsrfTokenValid(
            'unlock_project' . (string) $project->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            $this->addFlash('danger', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
        }

        $project->setLocked(false);
        $this->activityLogger->log($project, Activity::TYPE_PROJECT_UNLOCKED, $user);
        $this->entityManager->flush();
        $this->addFlash('success', 'Projet déverrouillé.');

        if ($request->headers->get('HX-Request')) {
            return new Response(null, 204, [
                'HX-Redirect' => $this->generateUrl('app_project_show', [
                    'id' => $project->getId(),
                    'tab' => 'ideas',
                ]),
            ]);
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'ideas']);
    }

    #[Route('/{id}/archive', name: 'app_project_archive', methods: ['POST'])]
    public function archiveProject(GiftProject $project, Request $request): Response
    {
        if (!$this->isGranted(GiftProjectVoter::MANAGE, $project)) {
            throw $this->createAccessDeniedException("Seul l'organisateur peut archiver le projet.");
        }

        if (!$this->isCsrfTokenValid(
            'archive_project' . (string) $project->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            $this->addFlash('danger', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('app_project_index', ['archived' => $project->isArchived() ? 1 : 0]);
        }

        $newState = !$project->isArchived();
        $project->setArchived($newState);
        $this->entityManager->flush();

        $status = $newState ? 'archivé' : 'désarchivé';
        $this->addFlash('success', "Projet {$status}.");

        return $this->redirectToRoute('app_project_index', ['archived' => $project->isArchived() ? 1 : 0]);
    }

    #[Route('/{id}/reimburse', name: 'app_project_reimburse', methods: ['POST'])]
    public function reimburse(GiftProject $project, Request $request, UserRepository $userRepository): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $this->denyUnlessCanViewProject($project, $currentUser);

        $debtorId = $request->getPayload()->getInt('debtor_id');
        $amount = $request->getPayload()->getString('amount');
        $debtor = $userRepository->find($debtorId);

        if (!$debtor || !$project->getParticipants()->contains($debtor) && $project->getOwner() !== $debtor) {
            $this->addFlash('danger', 'Utilisateur payeur invalide.');

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'financial']);
        }

        if ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
            $this->addFlash('danger', 'Montant de remboursement invalide.');

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'financial']);
        }

        $requestedCents = (int) round((float) $amount * 100);
        $owedCents = $this->computeOwedCents($project, $debtor, $currentUser);

        if ($requestedCents <= 0 || $owedCents <= 0 || $requestedCents > $owedCents) {
            $this->addFlash('danger', 'Aucune dette correspondante pour ce montant.');

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'financial']);
        }

        $formattedAmount = number_format($requestedCents / 100, 2, '.', '');

        if (!$this->isCsrfTokenValid(
            'reimburse_' . (string) $project->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            $this->addFlash('danger', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'financial']);
        }

        $reimbursement = new Reimbursement();
        $reimbursement->setProject($project);
        $reimbursement->setFromUser($debtor);
        $reimbursement->setToUser($currentUser);
        $reimbursement->setAmount($formattedAmount);

        $this->activityLogger->log($project, Activity::TYPE_REIMBURSEMENT_CREATED, $currentUser, [
            'amount' => $formattedAmount,
        ]);

        $this->entityManager->persist($reimbursement);
        $this->entityManager->flush();

        $this->addFlash('success', 'Remboursement confirmé.');

        if ($request->headers->get('HX-Request')) {
            return new Response(null, 204, [
                'HX-Redirect' => $this->generateUrl('app_project_show', [
                    'id' => $project->getId(),
                    'tab' => 'financial',
                ]),
            ]);
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'financial']);
    }

    private function computeOwedCents(GiftProject $project, User $debtor, User $creditor): int
    {
        $financials = $this->financialService->calculate($project);
        $owedCents = 0;

        foreach ($financials['transfers'] as $transfer) {
            if ($transfer['from']->getId() !== $debtor->getId() || $transfer['to']->getId() !== $creditor->getId()) {
                continue;
            }
            $owedCents += (int) round($transfer['amount'] * 100);
        }

        return $owedCents;
    }

    #[Route('/{id}/leave', name: 'app_project_leave', methods: ['POST'])]
    public function leaveProject(GiftProject $project, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($project->getOwner() === $user) {
            $this->addFlash('danger', 'L\'organisateur ne peut pas quitter le projet.');

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
        }

        $denied = $this->denyWhenDelivered($project, $request, 'details');
        if ($denied !== null) {
            return $denied;
        }

        if (!$project->getParticipants()->contains($user)) {
            $this->addFlash('warning', 'Vous ne faites pas partie de ce projet.');

            return $this->redirectToRoute('app_project_index');
        }

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

        if (!$this->isCsrfTokenValid(
            'leave_project' . (string) $project->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            $this->addFlash('danger', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('app_project_index');
        }

        $project->removeParticipant($user);
        $this->activityLogger->log($project, Activity::TYPE_PARTICIPANT_REMOVED, $user);
        $this->entityManager->flush();
        $this->addFlash('success', 'Vous avez quitté le projet.');

        if ($request->headers->get('HX-Request')) {
            return new Response(null, 204, [
                'HX-Redirect' => $this->generateUrl('app_project_index'),
            ]);
        }

        return $this->redirectToRoute('app_project_index');
    }

    #[Route('/{id}/remove-participant/{participant_id}', name: 'app_project_remove_participant', methods: ['POST'])]
    public function removeParticipant(
        GiftProject $project,
        #[MapEntity(id: 'participant_id')]
        User $participant,
        Request $request,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        if (!$this->isGranted(GiftProjectVoter::MANAGE, $project)) {
            throw $this->createAccessDeniedException("Seul l'organisateur peut retirer des participants.");
        }

        $denied = $this->denyWhenDelivered($project, $request, 'details');
        if ($denied !== null) {
            return $denied;
        }

        if (!$project->getParticipants()->contains($participant)) {
            $this->addFlash('warning', 'Cet utilisateur ne participe pas au projet.');

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
        }

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

        if (!$this->isCsrfTokenValid(
            'remove_participant' . (string) $participant->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            $this->addFlash('danger', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
        }

        $project->removeParticipant($participant);
        $this->activityLogger->log($project, Activity::TYPE_PARTICIPANT_REMOVED, $currentUser);
        $this->entityManager->flush();
        $this->addFlash('success', $participant->getDisplayName() . ' a été retiré du projet.');

        if ($request->headers->get('HX-Request')) {
            return new Response(null, 204, [
                'HX-Redirect' => $this->generateUrl('app_project_show', ['id' => $project->getId()]),
            ]);
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
    }

    #[Route('/{id}/mark-delivered', name: 'app_project_mark_delivered', methods: ['POST'])]
    public function markDelivered(GiftProject $project, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isGranted(GiftProjectVoter::MANAGE, $project)) {
            throw $this->createAccessDeniedException("Seul l'organisateur peut marquer le projet comme remis.");
        }

        if ($project->getDeliveredAt() !== null) {
            $this->addFlash('warning', 'Le projet est déjà marqué comme remis.');
            if ($request->headers->get('HX-Request')) {
                return new Response(null, 204, [
                    'HX-Redirect' => $this->generateUrl('app_project_show', ['id' => $project->getId()]),
                ]);
            }

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
        }

        if (!$this->isCsrfTokenValid(
            'mark_delivered' . (string) $project->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            $this->addFlash('danger', 'Jeton de sécurité invalide.');
        }

        if ($this->isCsrfTokenValid(
            'mark_delivered' . (string) $project->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            $project->setLocked(true);
            $project->setDeliveredAt(new \DateTimeImmutable());
            $this->activityLogger->log($project, Activity::TYPE_PROJECT_DELIVERED, $user);
            $this->entityManager->flush();
            $this->addFlash('success', 'Projet marqué comme remis au destinataire !');
        }

        if ($request->headers->get('HX-Request')) {
            return new Response(null, 204, [
                'HX-Redirect' => $this->generateUrl('app_project_show', ['id' => $project->getId()]),
            ]);
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
    }

    #[Route('/{id}/mark-read', name: 'app_project_mark_read', methods: ['POST'])]
    public function markAsRead(
        GiftProject $project,
        Request $request,
        ProjectReadStatusRepository $readStatusRepository,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $this->denyUnlessCanViewProject($project, $user);

        if (!$this->isCsrfTokenValid(
            'mark_read' . (string) $project->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            $this->addFlash('danger', 'Jeton de sécurité invalide.');

            return $this->redirectOrHtmx($request, 'app_project_show', ['id' => $project->getId()]);
        }

        $readStatusRepository->markAsRead($user, $project);
        $this->entityManager->flush();

        return $this->redirectOrHtmx($request, 'app_project_show', ['id' => $project->getId()]);
    }

    #[Route('/{id}/shares', name: 'app_project_update_shares', methods: ['POST'])]
    public function updateParticipantShares(GiftProject $project, Request $request): Response
    {
        if (!$this->isGranted(GiftProjectVoter::MANAGE, $project)) {
            throw $this->createAccessDeniedException("Seul l'organisateur peut modifier la répartition des parts.");
        }

        $denied = $this->denyWhenDelivered($project, $request, 'financial');
        if ($denied !== null) {
            return $denied;
        }

        if (!$this->isCsrfTokenValid(
            'update_shares_' . (string) $project->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            $this->addFlash('danger', 'Jeton de sécurité invalide.');
        }

        if ($this->isCsrfTokenValid(
            'update_shares_' . (string) $project->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            $allParams = $request->request->all();
            $sharesInput = is_array($allParams['shares'] ?? null) ? $allParams['shares'] : [];

            $updated = $this->financialService->updateParticipantShares($project, $sharesInput);
            if (!$updated) {
                $this->addFlash('warning', 'La somme des parts doit être supérieure à 0.');
            }
            if ($updated) {
                $this->entityManager->flush();
                $this->addFlash('success', 'Répartition des parts mise à jour.');
            }
        }

        if ($request->headers->get('HX-Request')) {
            $financials = $this->financialService->calculate($project);

            return $this->render('gift_project/_financial.html.twig', array_merge([
                'project' => $project,
            ], $financials));
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'financial']);
    }

    private function handleAddParticipant(GiftProject $project, User $user, User $newParticipant): void
    {
        if ($newParticipant === $project->getRecipient()) {
            $this->addFlash('warning', 'Vous ne pouvez pas inviter le destinataire du cadeau !');

            return;
        }

        if ($project->getParticipants()->contains($newParticipant) || $project->getOwner() === $newParticipant) {
            $this->addFlash('info', 'Cet utilisateur participe déjà.');

            return;
        }

        $project->addParticipant($newParticipant);
        $this->activityLogger->log($project, Activity::TYPE_PARTICIPANT_ADDED, $user);
        $this->entityManager->flush();

        $this->projectMailer->sendParticipantAddedEmail($project, $user, $newParticipant);

        $this->addFlash('success', 'Invitation envoyée à ' . $newParticipant->getDisplayName());
    }

    private function renderHtmxTab(
        string $tab,
        GiftProject $project,
        string $sort,
        FormInterface $addParticipantForm,
    ): Response {
        if ($tab === 'ideas') {
            return $this->render('gift_project/_ideas.html.twig', [
                'project' => $project,
                'gift_ideas' => $this->sortGiftIdeas($project, $sort),
                'current_sort' => $sort,
            ]);
        }

        if ($tab === 'discussion') {
            return $this->render('gift_project/_discussion.html.twig', [
                'project' => $project,
                'comment_form' => $this->createForm(CommentType::class)->createView(),
            ]);
        }

        if ($tab === 'liste_souhaits') {
            return $this->render('gift_project/_liste_souhaits.html.twig', [
                'project' => $project,
                'copied_wish_ids' => $this->getCopiedWishIds($project),
            ]);
        }

        if ($tab === 'financial') {
            $financials = $this->financialService->calculate($project);

            return $this->render('gift_project/_financial.html.twig', array_merge([
                'project' => $project,
            ], $financials));
        }

        if ($tab === 'activite') {
            return $this->render('gift_project/_activity.html.twig', [
                'project' => $project,
                'activities' => $this->activityRepository->findRecentForProject($project, 30),
            ]);
        }

        return $this->render('gift_project/_details.html.twig', [
            'project' => $project,
            'add_participant_form' => $addParticipantForm,
        ]);
    }

    /**
     * @return list<int>
     */
    private function getCopiedWishIds(GiftProject $project): array
    {
        $copiedWishIds = [];
        foreach ($project->getGiftIdeas() as $idea) {
            $fromWishId = $idea->getFromWish()?->getId();
            if ($fromWishId !== null) {
                $copiedWishIds[] = $fromWishId;
            }
        }

        return $copiedWishIds;
    }
}
