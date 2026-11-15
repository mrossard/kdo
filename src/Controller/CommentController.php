<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Activity;
use App\Entity\Comment;
use App\Entity\GiftProject;
use App\Entity\User;
use App\Form\CommentType;
use App\Security\Voter\CommentVoter;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/project')]
#[IsGranted('ROLE_USER')]
final class CommentController extends AbstractProjectController
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/{id}/comment', name: 'app_project_comment', methods: ['POST'])]
    public function addComment(Request $request, GiftProject $project): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->denyUnlessCanViewProject($project, $user);

        $denied = $this->denyWhenDelivered($project, $request, 'discussion');
        if ($denied !== null) {
            return $denied;
        }

        $comment = new Comment();
        $form = $this->createForm(CommentType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $comment->setAuthor($user);
            $project->addComment($comment);

            $this->activityLogger->log($project, Activity::TYPE_COMMENT_ADDED, $user);

            $this->entityManager->persist($comment);
            $this->entityManager->flush();

            if ($request->headers->get('HX-Request')) {
                return $this->render('gift_project/_discussion.html.twig', [
                    'project' => $project,
                    'comment_form' => $this->createForm(CommentType::class)->createView(),
                ]);
            }
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => 'discussion']);
    }

    #[Route('/comment/{id}', name: 'app_comment_show', methods: ['GET'])]
    public function showComment(Comment $comment): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $project = $this->requireCommentProject($comment);
        $this->denyUnlessCanViewProject($project, $user);

        return $this->render('gift_project/_comment.html.twig', [
            'comment' => $comment,
        ]);
    }

    #[Route('/comment/{id}/edit', name: 'app_comment_edit', methods: ['GET', 'POST'])]
    public function editComment(Request $request, Comment $comment): Response
    {
        $project = $this->requireCommentProject($comment);

        if (!$this->isGranted(CommentVoter::EDIT, $comment)) {
            throw $this->createAccessDeniedException('Vous ne pouvez modifier que vos propres commentaires.');
        }

        $denied = $this->denyWhenDelivered($project, $request, 'discussion');
        if ($denied !== null) {
            return $denied;
        }

        $form = $this->createForm(CommentType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            if ($request->headers->get('HX-Request')) {
                return $this->render('gift_project/_comment.html.twig', [
                    'comment' => $comment,
                ]);
            }

            return $this->redirectToRoute('app_project_show', [
                'id' => $project->getId(),
                'tab' => 'discussion',
            ]);
        }

        if ($request->headers->get('HX-Request')) {
            return $this->render('gift_project/_comment_edit.html.twig', [
                'comment' => $comment,
                'form' => $form,
            ]);
        }

        return $this->redirectToRoute('app_project_show', [
            'id' => $project->getId(),
            'tab' => 'discussion',
        ]);
    }

    #[Route('/comment/{id}/delete', name: 'app_comment_delete', methods: ['DELETE'])]
    public function deleteComment(Request $request, Comment $comment): Response
    {
        $project = $this->requireCommentProject($comment);

        if (!$this->isGranted(CommentVoter::DELETE, $comment)) {
            throw $this->createAccessDeniedException();
        }

        $denied = $this->denyWhenDelivered($project, $request, 'discussion');
        if ($denied !== null) {
            return $denied;
        }

        if ($this->isCsrfTokenValid(
            'delete_comment' . (string) $comment->getId(),
            $request->query->getString('_token'),
        )) {
            $this->entityManager->remove($comment);
            $this->entityManager->flush();

            if ($request->headers->get('HX-Request')) {
                return new Response('', Response::HTTP_OK);
            }
        }

        return $this->redirectToRoute('app_project_show', [
            'id' => $project->getId(),
            'tab' => 'discussion',
        ]);
    }

    private function requireCommentProject(Comment $comment): GiftProject
    {
        $project = $comment->getProject();
        if ($project === null) {
            throw $this->createNotFoundException();
        }

        return $project;
    }
}
