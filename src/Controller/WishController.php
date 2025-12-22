<?php

namespace App\Controller;

use App\Entity\Wish;
use App\Form\WishType;
use App\Repository\WishRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Attribute\Security;

#[Route('/wish')]
#[IsGranted('ROLE_USER')]
final class WishController extends AbstractController
{
    #[Route(name: 'app_wish_index', methods: ['GET'])]
    public function index(WishRepository $wishRepository, Request $request): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $showDelivered = $request->query->getBoolean('showDelivered', false); // Default to false
        $sort = $request->query->get('sort', 'name');
        $direction = $request->query->get('direction', 'ASC');

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
        $wish = new Wish();
        $form = $this->createForm(WishType::class, $wish);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $wish->setUser($this->getUser());
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
    #[Security("is_granted('ROLE_USER') and wish.getUser() == user")]
    public function show(Wish $wish): Response
    {
        return $this->render('wish/show.html.twig', [
            'wish' => $wish,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_wish_edit', methods: ['GET', 'POST'])]
    #[Security("is_granted('ROLE_USER') and wish.getUser() == user")]
    public function edit(Request $request, Wish $wish, EntityManagerInterface $entityManager): Response
    {
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
    #[Security("is_granted('ROLE_USER') and wish.getUser() == user")]
    public function delete(Request $request, Wish $wish, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete' . $wish->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($wish);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_wish_index', [], Response::HTTP_SEE_OTHER);
    }
}
