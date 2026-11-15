<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ManagedPerson;
use App\Entity\User;
use App\Form\ManagedPersonType;
use App\Repository\ManagedPersonRepository;
use App\Repository\WishlistShareRepository;
use App\Repository\WishRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ManagedPersonController extends AbstractController
{
    #[Route('/wish/person/{id}', name: 'app_wish_managed_index', methods: ['GET'])]
    public function managedIndex(
        ManagedPerson $managedPerson,
        WishRepository $wishRepository,
        ManagedPersonRepository $managedPersonRepository,
        Request $request,
    ): Response {
        $this->denyAccessUnlessManager($managedPerson);

        /** @var User $user */
        $user = $this->getUser();
        $showDelivered = $request->query->getBoolean('showDelivered', false);
        $sort = $request->query->getString('sort', 'name');
        $direction = $request->query->getString('direction', 'ASC');

        $wishes = $showDelivered
            ? $wishRepository->findAllManagedPersonWishesWithGiftIdeaDetails($managedPerson, $sort, $direction)
            : $wishRepository->findUndeliveredManagedPersonWishesWithGiftIdeaDetails($managedPerson, $sort, $direction);

        return $this->render('wish/index.html.twig', [
            'wishes' => $wishes,
            'show_delivered' => $showDelivered,
            'current_sort' => $sort,
            'current_direction' => $direction,
            'managed_persons' => $managedPersonRepository->findByManager($user),
            'current_managed_person' => $managedPerson,
        ]);
    }

    #[Route('/contact/managed/{id}', name: 'app_contact_managed_view', methods: ['GET'])]
    public function viewForContact(
        ManagedPerson $managedPerson,
        WishRepository $wishRepository,
        WishlistShareRepository $wishlistShareRepository,
        ManagedPersonRepository $managedPersonRepository,
    ): Response {
        /** @var User $me */
        $me = $this->getUser();
        $owner = $managedPerson->getManager();

        if (
            !$managedPerson->isManagedBy($me) && !$wishlistShareRepository->hasShareWithAnyManager($me, $managedPerson)
        ) {
            throw $this->createAccessDeniedException("Vous n'avez pas accès à cette liste.");
        }

        return $this->render('contact/view.html.twig', [
            'owner' => $owner,
            'managed_person' => $managedPerson,
            'managed_persons' => $owner !== null ? $managedPersonRepository->findByManager($owner) : [],
            'wishes' => $wishRepository->findUndeliveredManagedPersonWishesWithGiftIdeaDetails($managedPerson),
        ]);
    }

    #[Route('/managed-person/new', name: 'app_managed_person_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $managedPerson = new ManagedPerson();
        $form = $this->createForm(ManagedPersonType::class, $managedPerson, [
            'current_user' => $user,
            'can_manage_co_managers' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $managedPerson->setManager($user);
            $entityManager->persist($managedPerson);
            $entityManager->flush();

            $this->addFlash('success', "Liste créée pour {$managedPerson->getDisplayName()}.");

            return $this->redirectToRoute(
                'app_wish_managed_index',
                ['id' => $managedPerson->getId()],
                Response::HTTP_SEE_OTHER,
            );
        }

        return $this->render('managed_person/form.html.twig', [
            'managed_person' => $managedPerson,
            'form' => $form,
            'title' => 'Ajouter un proche sans compte',
            'button_label' => 'Créer la liste',
            'form_action' => $this->generateUrl('app_managed_person_new'),
        ]);
    }

    #[Route('/managed-person/{id}/edit', name: 'app_managed_person_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        ManagedPerson $managedPerson,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->denyAccessUnlessManager($managedPerson);

        /** @var User $user */
        $user = $this->getUser();
        $isPrimaryManager = $managedPerson->getManager() === $user;

        $form = $this->createForm(ManagedPersonType::class, $managedPerson, [
            'current_user' => $user,
            'can_manage_co_managers' => $isPrimaryManager,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', "Liste mise à jour pour {$managedPerson->getDisplayName()}.");

            return $this->redirectToRoute(
                'app_wish_managed_index',
                ['id' => $managedPerson->getId()],
                Response::HTTP_SEE_OTHER,
            );
        }

        return $this->render('managed_person/form.html.twig', [
            'managed_person' => $managedPerson,
            'form' => $form,
            'title' => 'Paramètres de la liste de ' . $managedPerson->getDisplayName(),
            'button_label' => 'Enregistrer',
            'form_action' => $this->generateUrl('app_managed_person_edit', ['id' => $managedPerson->getId()]),
        ]);
    }

    #[Route('/managed-person/{id}/delete', name: 'app_managed_person_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        ManagedPerson $managedPerson,
        EntityManagerInterface $entityManager,
    ): Response {
        if ($managedPerson->getManager() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Seul le créateur de cette liste peut la supprimer.');
        }

        if ($this->isCsrfTokenValid(
            'delete_managed_person' . (string) $managedPerson->getId(),
            $request->getPayload()->getString('_token'),
        )) {
            $name = $managedPerson->getDisplayName();
            $entityManager->remove($managedPerson);
            $entityManager->flush();

            $this->addFlash('info', "La liste de {$name} a été supprimée.");
        }

        return $this->redirectToRoute('app_wish_index', [], Response::HTTP_SEE_OTHER);
    }

    private function denyAccessUnlessManager(ManagedPerson $managedPerson): void
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$managedPerson->isManagedBy($user)) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas gérer cette liste.');
        }
    }
}
