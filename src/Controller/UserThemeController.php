<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class UserThemeController extends AbstractController
{
    #[Route('/theme/toggle', name: 'app_theme_toggle', methods: ['POST'])]
    public function toggle(EntityManagerInterface $entityManager): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $prefs = $user->getUiPreferences() ?? [];

        // Default is light, so if not set or light, switch to dark
        /** @var string|null $rawTheme */
        $rawTheme = $prefs['theme'] ?? null;
        $currentTheme = is_string($rawTheme) ? $rawTheme : 'light';
        $newTheme = $currentTheme === 'light' ? 'dark' : 'light';

        $prefs['theme'] = $newTheme;
        $user->setUiPreferences($prefs);

        $entityManager->flush();

        // Return updated button HTML and set X-Kdo-Theme header for instant HTMX sync
        $response = $this->render('theme/_toggle_button.html.twig', [
            'theme' => $newTheme,
        ]);
        $response->headers->set('X-Kdo-Theme', $newTheme);

        return $response;
    }
}
