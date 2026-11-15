<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ManagedPerson;
use App\Entity\User;
use App\Entity\Wish;
use App\Form\WishType;
use App\Repository\ManagedPersonRepository;
use App\Repository\WishRepository;
use App\Service\UrlMetadataFetcher;
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
    public function index(
        WishRepository $wishRepository,
        ManagedPersonRepository $managedPersonRepository,
        Request $request,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $showDelivered = $request->query->getBoolean('showDelivered', false);
        $sort = $request->query->getString('sort', 'name');
        $direction = $request->query->getString('direction', 'ASC');

        $wishes = $showDelivered
            ? $wishRepository->findAllUserWishesWithGiftIdeaDetails($user, $sort, $direction)
            : $wishRepository->findUndeliveredUserWishesWithGiftIdeaDetails($user, $sort, $direction);

        return $this->render('wish/index.html.twig', [
            'wishes' => $wishes,
            'show_delivered' => $showDelivered,
            'current_sort' => $sort,
            'current_direction' => $direction,
            'managed_persons' => $managedPersonRepository->findByManager($user),
            'current_managed_person' => null,
        ]);
    }

    #[Route('/new', name: 'app_wish_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        ManagedPersonRepository $managedPersonRepository,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $managedPerson = null;
        $managedPersonId = $request->query->getInt('managedPerson');
        if ($managedPersonId > 0) {
            $managedPerson = $managedPersonRepository->find($managedPersonId);
            if ($managedPerson === null || !$managedPerson->isManagedBy($user)) {
                throw $this->createAccessDeniedException('Vous ne pouvez pas ajouter un souhait à cette liste.');
            }
        }

        $wish = new Wish();
        $form = $this->createForm(WishType::class, $wish);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $managedPerson !== null ? $wish->setManagedPerson($managedPerson) : $wish->setUser($user);
            $entityManager->persist($wish);
            $entityManager->flush();

            return $this->redirectToWishList($managedPerson);
        }

        return $this->render('wish/new.html.twig', [
            'wish' => $wish,
            'form' => $form,
            'managed_person' => $managedPerson,
        ]);
    }

    #[Route('/autofill', name: 'app_wish_autofill', methods: ['POST'])]
    public function autofill(Request $request, UrlMetadataFetcher $metadataFetcher): Response
    {
        $allParams = $request->request->all();
        /** @var array<string, string|null> $wishData */
        $wishData = is_array($allParams['wish'] ?? null) ? $allParams['wish'] : [];
        $wish = new Wish();
        $result = $metadataFetcher->populateFromFormData($wish, $wishData);

        $formAction = $request->query->getString('form_action', $this->generateUrl('app_wish_new'));
        $buttonLabel = $request->query->getString('button_label', 'Enregistrer');
        $form = $this->createForm(WishType::class, $wish);

        return $this->render('wish/_form.html.twig', [
            'form' => $form,
            'form_action' => $formAction,
            'button_label' => $buttonLabel,
            'autofill_message' => $result['message'],
            'autofill_status' => $result['status'],
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

            $managedPerson = $wish->getManagedPerson();
            return $this->redirectToWishList($managedPerson);
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
        $managedPerson = $wish->getManagedPerson();

        if (!$this->isCsrfTokenValid('delete' . (string) $wish->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide.');

            return $this->redirectToWishList($managedPerson);
        }

        $entityManager->remove($wish);
        $entityManager->flush();
        $this->addFlash('info', 'Souhait supprimé.');

        if ($request->headers->get('HX-Request')) {
            return new Response('', Response::HTTP_OK);
        }

        return $this->redirectToWishList($managedPerson);
    }

    private function redirectToWishList(?ManagedPerson $managedPerson): Response
    {
        if ($managedPerson !== null) {
            return $this->redirectToRoute(
                'app_wish_managed_index',
                ['id' => $managedPerson->getId()],
                Response::HTTP_SEE_OTHER,
            );
        }

        return $this->redirectToRoute('app_wish_index', [], Response::HTTP_SEE_OTHER);
    }

    private function denyAccessUnlessOwner(Wish $wish): void
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$wish->isManagedBy($user)) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas accéder à ce souhait.');
        }
    }
}
