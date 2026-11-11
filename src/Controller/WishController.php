<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\Wish;
use App\Form\WishType;
use App\Repository\WishRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/wish')]
#[IsGranted('ROLE_USER')]
final class WishController extends AbstractController
{
    #[Route(name: 'app_wish_index', methods: ['GET'])]
    public function index(WishRepository $wishRepository, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $showDelivered = $request->query->getBoolean('showDelivered', false);
        $sort = $request->query->getString('sort', 'name');
        $direction = $request->query->getString('direction', 'ASC');

        return $this->render('wish/index.html.twig', [
            'wishes' => $wishRepository->findUserWishesWithGiftIdeaDetails($user, $showDelivered, $sort, $direction),
            'show_delivered' => $showDelivered,
            'current_sort' => $sort,
            'current_direction' => $direction,
        ]);
    }

    #[Route('/new', name: 'app_wish_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $wish = new Wish();
        $form = $this->createForm(WishType::class, $wish);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $wish->setUser($user);
            $entityManager->persist($wish);
            $entityManager->flush();

            return $this->redirectToRoute('app_wish_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('wish/new.html.twig', [
            'wish' => $wish,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_wish_show', methods: ['GET'])]
    public function show(Wish $wish): Response
    {
        $this->denyAccessUnlessOwner($wish);

        return $this->render('wish/show.html.twig', [
            'wish' => $wish,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_wish_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Wish $wish, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessOwner($wish);

        $form = $this->createForm(WishType::class, $wish);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_wish_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('wish/edit.html.twig', [
            'wish' => $wish,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_wish_delete', methods: ['POST'])]
    public function delete(Request $request, Wish $wish, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessOwner($wish);

        if ($this->isCsrfTokenValid('delete' . $wish->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($wish);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_wish_index', [], Response::HTTP_SEE_OTHER);
    }

    private function denyAccessUnlessOwner(Wish $wish): void
    {
        if ($wish->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas accéder à ce souhait.');
        }
    }
}
