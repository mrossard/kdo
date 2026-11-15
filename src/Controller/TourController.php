<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class TourController extends AbstractController
{
    public const int TOTAL_STEPS = 6;

    #[Route(
        '/tour/modal/{step}',
        name: 'app_tour_modal',
        requirements: ['step' => '\d+'],
        defaults: ['step' => 1],
        methods: ['GET'],
    )]
    public function modal(int $step, Request $request): Response
    {
        $step = max(1, min(self::TOTAL_STEPS, $step));

        if ($request->query->getBoolean('content_only', false)) {
            return $this->render('tour/_modal_content.html.twig', [
                'step' => $step,
                'total_steps' => self::TOTAL_STEPS,
            ]);
        }

        return $this->render('tour/_modal.html.twig', [
            'step' => $step,
            'total_steps' => self::TOTAL_STEPS,
        ]);
    }

    #[Route('/tour/complete', name: 'app_tour_complete', methods: ['POST'])]
    public function complete(EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $prefs = $user->getUiPreferences() ?? [];
        $prefs['tour_completed'] = true;
        $user->setUiPreferences($prefs);
        $entityManager->flush();

        $this->addFlash('success', 'Visite guidée terminée ! Vous pouvez la relancer à tout moment depuis le menu.');

        return new Response('', Response::HTTP_OK);
    }

    #[Route('/tour/dismiss', name: 'app_tour_dismiss', methods: ['POST'])]
    public function dismiss(EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $prefs = $user->getUiPreferences() ?? [];
        $prefs['tour_completed'] = true;
        $user->setUiPreferences($prefs);
        $entityManager->flush();

        return new Response('');
    }

    #[Route('/tour/reset', name: 'app_tour_reset', methods: ['POST'])]
    public function reset(Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('tour_reset', $request->getPayload()->getString('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_profile');
        }

        /** @var User $user */
        $user = $this->getUser();
        $prefs = $user->getUiPreferences() ?? [];
        $prefs['tour_completed'] = false;
        $user->setUiPreferences($prefs);
        $entityManager->flush();

        $this->addFlash('info', 'La visite guidée sera de nouveau suggérée sur votre tableau de bord.');

        return $this->redirectToRoute('app_profile');
    }

    #[Route('/guide', name: 'app_tour_guide', methods: ['GET'])]
    public function guide(): Response
    {
        return $this->render('tour/guide.html.twig', [
            'total_steps' => self::TOTAL_STEPS,
        ]);
    }
}
