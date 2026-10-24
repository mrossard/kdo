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
        $currentTheme = $prefs['theme'] ?? 'light';
        $newTheme = $currentTheme === 'light' ? 'dark' : 'light';

        $prefs['theme'] = $newTheme;
        $user->setUiPreferences($prefs);

        $entityManager->flush();

        // If HTMX request, we can return a partial or just a 200 OK with headers
        // But to update the button icon, returning the new button HTML is best.
        return $this->render('theme/_toggle_button.html.twig', [
            'theme' => $newTheme,
        ]);
    }
}
